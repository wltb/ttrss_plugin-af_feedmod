<?php

class Af_Feedmod extends Plugin implements IHandler
{
    private $host;

    function about()
    {
        return array(
            1.0,   // version
            'Replace feed contents by contents from the linked page',   // description
            'mbirth',   // author
            false,   // is_system
        );
    }

    function api_version()
    {
        return 2;
    }

    function init($host)
    {
        $this->host = $host;

        $host->add_hook($host::HOOK_PREFS_TABS, $this);
# only allowed for system plugins:        $host->add_handler('pref-feedmod', '*', $this);
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    }

    function csrf_ignore($method)
    {
        $csrf_ignored = array("index", "edit");
        return array_search($method, $csrf_ignored) !== false;
    }

    function before($method)
    {
        if ($_SESSION["uid"]) {
            return true;
        }
        return false;
    }

    function after()
    {
        return true;
    }

    function hook_article_filter($article)
    {
        $json_conf = $this->host->get($this, 'json_conf');
        $owner_uid = $article['owner_uid'];
        $data = json_decode($json_conf, true);

        if (!is_array($data)) {
            // no valid JSON or no configuration at all
            return $article;
        }

        foreach ($data as $urlpart=>$config) {
            if (strpos($article['link'], $urlpart) === false) continue;   // skip this config if URL not matching
            if (strpos($article['plugin_data'], "feedmod,$owner_uid:") !== false) {
                // do not process an article more than once
                if (isset($article['stored']['content'])) $article['content'] = $article['stored']['content'];
                break;
            }

            $link = trim($article['link']);

            switch ($config['type']) {
                case 'xpath':
                    $doc = $this->fetch_page($link, $config);

                    if(!isset($config['next']))
                        $content = $this->extract_xpath($doc, $config);
                    else {
                        for ($i = 0, $content = '', $base_link = $link, $new_link = ''; $i < 50; $i++) {
                            //extract & append content
                            $content .= $this->extract_xpath($doc, $config, $doc->documentURI != $base_link, $new_link);

                            //check next link
                            if($new_link !== NULL) {
                                $link = rewrite_relative_url($link, $new_link);
                                if(isset($config['SOP']) && $config['SOP'] === TRUE
                                    && strpos($link, $base_link) === FALSE)
                                    break;
                            }
                            else
                                break;

                            //fetch stuff for next iteration
                            $doc = $this->fetch_page($link, $config);
                        }
                    }

                    if($content != '') {
                        $article['content'] = $content;
                        $article['plugin_data'] = "feedmod,$owner_uid:" . $article['plugin_data'];
                    }
                    break;

                default:
                    // unknown type or invalid config
                    continue;
            }

            break;   // if we got here, we found the correct entry in $data, do not process more
        }

        return $article;
    }

    //helper functions
    function fetch_page($link, $config)
    {
        global $fetch_last_content_type;

        if (version_compare(VERSION, '1.7.9', '>=')) {
            foreach(array('login', 'pass', 'post_query') as $par) {
                if(isset($config['fetch_parameters'][$par]))
                    ${$par} = $config['fetch_parameters'][$par];
                else
                    ${$par} = false;
            }
            $html = fetch_file_contents($link, false, $login, $pass, $post_query);

            $content_type = $fetch_last_content_type;
        } else {
            // fallback to file_get_contents()
            $html = file_get_contents($link);

            // try to fetch charset from HTTP headers
            $headers = $http_response_header;
            $content_type = false;
            foreach ($headers as $h) {
                if (substr(strtolower($h), 0, 13) == 'content-type:') {
                    $content_type = substr($h, 14);
                    // don't break here to find LATEST (if redirected) entry
                }
            }
        }

        if (!isset($config['force_charset'])) {
            $charset = false;
            if ($content_type) {
                preg_match('/charset=(\S+)/', $content_type, $matches);
                if (isset($matches[1]) && !empty($matches[1])) $charset = $matches[1];
            }
        } else {
            // use forced charset
            $charset = $config['force_charset'];
        }

        if ($charset) {
            //via hakre (http://stackoverflow.com/a/11310258)
            $make_html_head = function($enc) { return '<head>
                    <meta http-equiv="Content-Type" content="text/html; charset=' . $enc .'"/>
                </head>'; };

            // libxml compiled without iconv?
            libxml_use_internal_errors(true);
            libxml_clear_errors();

            $doc = new DOMDocument();
            $doc->loadXML('<?xml version="1.0" encoding="' . $charset . '"?> <tag/>');
            $error = libxml_get_last_error();
            if ($error && $error->code == 32) {
                mb_substitute_character("none");
                $html = mb_convert_encoding($html, 'UTF-8', $charset);
                $html_head = $make_html_head('UTF-8');
            }
            else
                $html_head = $make_html_head($charset);

            libxml_use_internal_errors(false);
        } else $html_head = '';

        $doc = new DOMDocument();
        @$doc->loadHTML($html_head . $html);
        $doc->documentURI = $link;

        return $doc;
    }

    function extract_xpath(DomDocument $doc, $config, $rewrite = false, &$next = null)
    {
        if ($doc) {
            $basenode = false;
            $xpath = new DOMXPath($doc);
            $entries = $xpath->query('(//'.$config['xpath'].')');   // find main DIV according to config

            if ($entries->length > 0) $basenode = $entries->item(0);

            //get next link here since it may be removed by the cleanup
            if ($next !== null && isset($config['next']))
               $next = $xpath->query('(//'.$config['next'].')')->item(0)->textContent;

            if ($basenode) {
                // remove nodes from cleanup configuration
                if (isset($config['cleanup'])) {
                    if (!is_array($config['cleanup'])) {
                        $config['cleanup'] = array($config['cleanup']);
                    }
                    foreach ($config['cleanup'] as $cleanup) {
                        $nodelist = $xpath->query('.//'.$cleanup, $basenode);
                        foreach ($nodelist as $node) {
                            if ($node instanceof DOMAttr) {
                                $node->ownerElement->removeAttributeNode($node);
                            }
                            else {
                                $node->parentNode->removeChild($node);
                            }
                        }
                    }
                }

                if ($rewrite) {
                    //rewrite relative URLs
                    $entries = $xpath->query('(.//img[@src]/@src/text()|.//a[@href]/@href/text())', $basenode);
                    foreach($entries as $entry) {
                        $entry->nodeValue = rewrite_relative_url($doc->documentURI, $entry->textContent);
                    }
                }

                return $doc->saveXML($basenode);
            }
        }
        return '';
    }

    function hook_prefs_tabs($args)
    {
        print '<div id="feedmodConfigTab" dojoType="dijit.layout.ContentPane"
            href="backend.php?op=af_feedmod"
            title="' . __('FeedMod') . '"></div>';
    }

    function index()
    {
        $pluginhost = PluginHost::getInstance();
        $json_conf = $pluginhost->get($this, 'json_conf');

        print "<form dojoType=\"dijit.form.Form\">";

        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
            if (this.validate()) {
                new Ajax.Request('backend.php', {
                    parameters: dojo.objectToQuery(this.getValues()),
                    onComplete: function(transport) {
                        if (transport.responseText.indexOf('error')>=0) notify_error(transport.responseText);
                            else notify_info(transport.responseText);
                    }
                });
                //this.reset();
            }
            </script>";

        print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
        print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
        print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"af_feedmod\">";

        print "<table width='100%'><tr><td>";
        print "<textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"json_conf\" style=\"font-size: 12px; width: 99%; height: 500px;\">" . htmlspecialchars($json_conf, ENT_NOQUOTES, 'UTF-8') . "</textarea>";
        print "</td></tr></table>";

        print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button>";

        print "</form>";
    }

    function save()
    {
        $json_conf = $_POST['json_conf'];

        if (is_null(json_decode($json_conf))) {
            echo __("error: Invalid JSON!");
            return false;
        }

        $this->host->set($this, 'json_conf', $json_conf);
        echo __("Configuration saved.");
    }

}
