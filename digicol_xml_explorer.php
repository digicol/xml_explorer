<?php

// Licensed under the PHP License
//
// This is experimental code, not well-documented...
// Use at your own risk!
//
// Tim Strehle <tim@digicol.de>

require_once('digicol_console_options.class.php');


class Digicol_Xml_Explorer
{
    protected $select = array();
    protected $stats = false;
    protected $namespace_prefixes = array();
    protected $exit_on_unknown_options = true;
    
    
    public function getHelpText()
    {
        return <<<EOT

  Explore XML structures.

  Read and parse multiple XML files and display a report summarizing their
  structure.

  Use this when you're being provided with a bunch of sample XML files and don't
  know what they actually contain.

  Set <file> to "-" to read file names or XML from STDIN. (This allows you to use
  digicol_xml_explorer.php in conjunction with "find".)

  Usage: php digicol_xml_explorer.php [ OPTIONS ] <file> [<file> ...]

    -s, --select <tag>          Select values of these XPath expressions and list 
                                them with counts.
                                (Optional, multiple values allowed).

        --xmlns prefix=uri      Define namespace prefixes to be used in output.
                                If you don't set this option, namespaces will be
                                displayed using auto-generated prefixes, e.g. ns1.
                                (Optional, multiple values allowed).
        
    -h, --help             Display this help message and exit.

  Copyright 2003-2012 by Digital Collections Verlagsgesellschaft mbH.
  Report bugs to: <tim@digicol.de>


EOT;

    }


    public function main($script_filename, $argv)
    {
        $this->parseOptions($argv);
        $this->determineAction();       
        $this->executeAction();
    }


    public function parseOptions($argv)
    {
        $getopt = new Digicol_Console_Options($this->getOptionDefinitions());

        $this->options = $getopt->parse($argv, $this->exit_on_unknown_options);
        
        if (is_string($this->options))
        {
            fwrite(STDERR, sprintf("Unknown option \"%s\". Try -h for more information.\n", $this->options));
            exit(1);
        }
    }


    protected function getOptionDefinitions()
    {
        return array
        (
            array( 'xmlns'       , '+' ),
        	array( 'select, s'   , '+' ),
            array( 'help, h'     ,  0  )
        );
    }


    public function determineAction()
    {
        $this->action = '';
        
        if (isset($this->options[ 'help' ]))
        {
            $this->action = 'help';
            return;
        }
            
        if (count($this->options[ '_' ]) > 0)
            $this->action = 'explore';
    }
    
    
    public function executeAction()
    {
        if ($this->action == '')
        {
            fwrite(STDERR, "Nothing to do. Try -h for more information.\n");
            exit(1);
        }

        $method = 'executeAction_' . $this->action;
        
        $this->$method();
    }


    protected function executeAction_help()
    {
        fwrite(STDOUT, $this->getHelpText());
    }


    protected function executeAction_explore()
    {
        // Initialize

        $this->stats = array
        (
        	'xml_parse_error' => 0,
        	'xml_parsed'      => 0,
        	'all_tags'        => array(),
        	'all_attributes'  => array(),
        	'all_xpaths'      => array(),
        	'all_namespaces'  => array(),
        	'select' => array()
    	);

        $this->namespace_prefixes = array();
        
        if (isset($this->options[ 'xmlns' ]))
        {
            foreach ($this->options[ 'xmlns' ] as $str)
            {
                if (! preg_match('/^([a-z0-9]+)=(.+)$/i', $str, $matches))
                {
                    fwrite(STDERR, "Wrong --xmlns parameter format.\n");
                    exit(1);
                }
                    
                $this->namespace_prefixes[ $matches[ 2 ] ] = $matches[ 1 ];
            }
        }
        
        $this->select = array();

        if (isset($this->options[ 'select' ]))
        {
            $this->select = $this->options[ 'select' ];

            foreach ($this->select as $sel)
                $this->stats[ 'select' ][ $sel ] = array();
        }

        // Read from STDIN?

        if ($this->options[ '_' ][ 0 ] === '-')
        {
            $first = true;
            $mode = 'filename';
            $buffer = '';

            while (! feof(STDIN))
            {
                $line = trim(fgets(STDIN));

                // Check input - if the first line doesn't look like XML, we're in filename mode

                if ($first)
                {
                    if (substr($line, 0, 6) == '<?xml ')
                        $mode = 'buffer';
                }

                if ($mode == 'buffer')
                {
                    // Check whether a new XML has been started

                    if (substr($line, 0, 6) == '<?xml ')
                    {
                        if ($buffer != '')
                            $this->processFile($buffer, 'buffer');

                        $buffer = $line;
                    }
                    else
                    {
                        $buffer .= $line . "\n";
                    }
                }
                elseif ($line != '')
                {
                    $this->processFile($line, 'filename');
                }

                $first = false;
            }

            if ($mode == 'buffer')
            {
                if ($buffer != '')
                    $this->processFile($buffer, 'buffer');
            }
        }
        else
        {
            foreach ($this->options[ '_' ] as $filename)
                $this->processFile($filename, 'filename');
        }

        // Clean up namespaces
        
        $this->cleanupNamespaces();

        // Summarize

        $this->summarize();
    }


    protected function processFile($filename, $mode)
    {
        if ($mode == 'filename')
        {
            if (! file_exists($filename))
            {
                fwrite(STDERR, sprintf("File <%s> does not exist.\n", $filename));
                return;
            }
        }

        fwrite(STDOUT, '#');

        if ($mode == 'filename')
        {
            $ok = $this->checkXML(file_get_contents($filename), $results, $filename);
        }
        elseif ($mode == 'buffer')
        {
            $ok = $this->checkXML($filename, $results, 'stdin');
        }

        if ($ok < 0)
        {
            $this->stats[ 'xml_parse_error' ]++;
            return;
        }

        $this->stats[ 'xml_parsed' ]++;

        $this->stats[ 'all_tags' ] = array_unique
        (
            array_merge($this->stats[ 'all_tags' ], $results[ 'all_tags' ])
        );
        
        $this->stats[ 'all_attributes' ] = array_unique
        (
            array_merge($this->stats[ 'all_attributes' ], $results[ 'all_attributes' ])
        );

        $this->stats[ 'all_namespaces' ] = array_unique
        (
            array_merge($this->stats[ 'all_namespaces' ], $results[ 'all_namespaces' ])
        );

        foreach ($results[ 'select' ] as $key => $values)
        {
            foreach ($values as $value)
            {
                if (! isset($this->stats[ 'select' ][ $key ][ $value ]))
                    $this->stats[ 'select' ][ $key ][ $value ] = 0;
                    
                $this->stats[ 'select' ][ $key ][ $value ]++;
            }
	    }

        foreach ($results[ 'all_xpaths' ] as $xpath => $values)
        {
            if (! isset($this->stats[ 'all_xpaths' ][ $xpath ]))
            {
                $this->stats[ 'all_xpaths' ][ $xpath ] = array
                (
                    'f' => 0,
                    'm' => 0,
                    'l' => 0,
                    'b' => false
                );
			}

            $this->stats[ 'all_xpaths' ][ $xpath ][ 'f' ]++;

            if ($values[ 'm' ] > $this->stats[ 'all_xpaths' ][ $xpath ][ 'm' ])
                $this->stats[ 'all_xpaths' ][ $xpath ][ 'm' ] = $values[ 'm' ];

            if ($values[ 'l' ] > $this->stats[ 'all_xpaths' ][ $xpath ][ 'l' ])
                $this->stats[ 'all_xpaths' ][ $xpath ][ 'l' ] = $values[ 'l' ];

            if ($values[ 'b' ])
                $this->stats[ 'all_xpaths' ][ $xpath ][ 'b' ] = true;
        }
    }


    protected function checkXML($buffer, &$results, $filename)
    {
        $results = array
        (
            'all_tags'       => array(),
            'all_attributes' => array(),
            'all_xpaths'     => array(),
            'all_namespaces' => array(),
            'select' => array()
        );

        // Parse XML file

        $dom = new DOMDocument();

        $ok = $dom->loadXML($buffer);

        if ($ok === false)
        {
            // XXX we should output error information from the XML parser!
            fwrite(STDERR, sprintf("\nCould not parse XML in <%s>\n", $filename));
            return -1;
        }

        // Look for namespaces registered anywhere in the XML
        
        $xpath = new DOMXPath($dom);
        
        foreach ($xpath->query('//namespace::*') as $xmlns_node) 
        {
            // Skip built-in xmlns:xml="http://www.w3.org/XML/1998/namespace"
            
            if ($xmlns_node->prefix === 'xml')
                continue;
                
            if (! in_array($xmlns_node->nodeValue, $results[ 'all_namespaces' ]))
                $results[ 'all_namespaces' ][ ] = $xmlns_node->nodeValue;
        
            if (strlen($xmlns_node->prefix) > 0)
                $this->namespace_prefixes[ $xmlns_node->nodeValue ] = $xmlns_node->prefix;
        }

        // Start recursive processing on root node

        $this->processNode($dom->documentElement, '/', &$results);
        
        // Fetch XPath select values
        
        $this->processSelect($dom, &$results);
        
        return 1;        
    }


    protected function processNode($node, $xpath, &$results)
    {
        if ($node->nodeType !== XML_ELEMENT_NODE)
            return;
        
        $ns_name = $node->localName;
        
        if (strlen($node->namespaceURI) > 0)
        {
            $ns_name = '<' . $node->namespaceURI . '>' . $ns_name;

            if (! in_array($node->namespaceURI, $results[ 'all_namespaces' ]))
                $results[ 'all_namespaces' ][ ] = $node->namespaceURI;
        
            if (strlen($node->prefix) > 0)
                $this->namespace_prefixes[ $node->namespaceURI ] = $node->prefix;
        }
        
        if (! in_array($ns_name, $results[ 'all_tags' ]))
            $results[ 'all_tags' ][ ] = $ns_name;

        $xpath .= $ns_name;

        $this->addNodeInfo($xpath, $node->nodeValue, $results);
        
        if ($node->hasAttributes())
        {
            foreach ($node->attributes as $attribute)
            {
                if (! in_array($attribute->nodeName, $results[ 'all_attributes' ]))
                    $results[ 'all_attributes' ][ ] = $attribute->nodeName;
                    
                $attrib_xpath = $xpath . '/@' . $attribute->nodeName;
                
                $this->addNodeInfo($attrib_xpath, $attribute->value, $results);
            }
        }
        
        // XXX to be implemented: detect mixed content, empty tags, text tags, ...
        
        if ($node->hasChildNodes())
        {
            foreach ($node->childNodes as $childnode)
                $this->processNode($childnode, $xpath . '/', $results);
        }
    }    
    

    protected function addNodeInfo($xpath, $value, &$results)
    {    
        if (! isset($results[ 'all_xpaths' ][ $xpath ]))
        {
            $results[ 'all_xpaths' ][ $xpath ] = array
            (
                'm' => 0,
                'l' => 0,
                'b' => false
            );
        }

        $results[ 'all_xpaths' ][ $xpath ][ 'm' ]++;
    
        $value = trim($value);
        $l = strlen($value);

        if ($l > $results[ 'all_xpaths' ][ $xpath ][ 'l' ])
            $results[ 'all_xpaths' ][ $xpath ][ 'l' ] = $l;

        // XXX we get multiline = yes for the XML root tag; does $node->nodeValue
        // contain all child node values??
        
        if (is_int(strpos($value, "\n")) || is_int(strpos($value, "\r")))
            $results[ 'all_xpaths' ][ $xpath ][ 'b' ] = true;
    }
    
    
    protected function processSelect(DOMDocument $dom, &$results)
    {
        if (! isset($this->options[ 'select' ]))
            return;
            
        $xpath = new DOMXPath($dom);

        // XXX note that cleanupNamespaces() has not been run yet (runs at the
        // very end), so the user can only use prefixes explicitly defined in
        // the document or specified using --xmlns in his XPath selects.
        
        foreach ($this->namespace_prefixes as $namespace_uri => $namespace_prefix)
            $xpath->registerNameSpace($namespace_prefix, $namespace_uri);

        foreach ($this->options[ 'select' ] as $select)
        {
            if (! isset($results[ 'select' ][ $select ]))
                $results[ 'select' ][ $select ] = array();

            foreach ($xpath->query($select) as $node)
                $results[ 'select' ][ $select ][ ] = (string) $node->nodeValue;
        }

        foreach ($results[ 'select' ] as $select => $values)
            $results[ 'select' ][ $select ] = array_unique($results[ 'select' ][ $select ]);
    }
    
    
    protected function cleanupNamespaces()
    {
        // If the XML does not declare any NsPrefix for a NsUri, auto-generate
        // one
        
        $suffix = 1;
        
        foreach ($this->stats[ 'all_namespaces' ] as $namespace_uri)
        {
            if (isset($this->namespace_prefixes[ $namespace_uri ]))
                continue;

            while (true)
            {
                $namespace_prefix = 'ns' . $suffix;
                
                if (! in_array($namespace_prefix, $this->namespace_prefixes))
                    break;
                    
                $suffix++;
            }
            
            $this->namespace_prefixes[ $namespace_uri ] = $namespace_prefix;
        }

        // <NsUri>tag => NsPrefix:tag in "all_tags" and "all_xpaths"
        
        $replace = array();
        
        foreach ($this->namespace_prefixes as $namespace_uri => $namespace_prefix)
            $replace[ '<' . $namespace_uri . '>' ] = $namespace_prefix . ':';

        foreach ($this->stats[ 'all_tags' ] as $key => $tagname)
            $this->stats[ 'all_tags' ][ $key ] = strtr($tagname, $replace);

        $old_keys = array_keys($this->stats[ 'all_xpaths' ]);
        
        foreach ($old_keys as $old_key)
        {
            $new_key = strtr($old_key, $replace);
            
            if ($new_key === $old_key)
                continue;
                
            $this->stats[ 'all_xpaths' ][ $new_key ] = $this->stats[ 'all_xpaths' ][ $old_key ];
            
            unset($this->stats[ 'all_xpaths' ][ $old_key ]);
        }
    }
    
    
    protected function summarize()
    {
        fwrite(STDOUT, sprintf
        (
            "\n\nDone. Successfully parsed %d files. XML parse errors in %d files.\n",
            $this->stats[ 'xml_parsed' ],
            $this->stats[ 'xml_parse_error' ]
        ));

        sort($this->stats[ 'all_namespaces' ]);
        sort($this->stats[ 'all_tags'       ]);
        sort($this->stats[ 'all_attributes' ]);
        ksort($this->stats[ 'all_xpaths'    ]);

        fwrite(STDOUT, sprintf
        (
            "\n%d XML namespaces found:\n",
            count($this->stats[ 'all_namespaces' ])
        ));
        
        foreach ($this->stats[ 'all_namespaces' ] as $namespace_uri)
        {
            fwrite(STDOUT, sprintf
            (
                'xmlns:%s="%s"' . "\n",
                $this->namespace_prefixes[ $namespace_uri ],
                $namespace_uri
            ));
        }
        
        fwrite(STDOUT, sprintf
        (
            "\n%d XML tags found:\n%s\n",
            count($this->stats[ 'all_tags' ]),
            implode(', ', $this->stats[ 'all_tags' ])
        ));
        
        fwrite(STDOUT, sprintf
        (
            "\n%d XML attributes found:\n%s\n",
            count($this->stats[ 'all_attributes' ]),
            implode(', ', $this->stats[ 'all_attributes' ])
        ));

        fwrite(STDOUT, "\n" . str_repeat('=', 100) . "\n");
        
        fwrite
        (
            STDOUT,
            str_pad('XPath', 76) .
            str_pad('Files', 6, ' ', STR_PAD_LEFT) .
            str_pad('Occur', 6, ' ', STR_PAD_LEFT) .
            str_pad('Length', 8, ' ', STR_PAD_LEFT) .
            str_pad('ML', 4, ' ', STR_PAD_LEFT) .
            "\n"
        );
        
        fwrite(STDOUT, str_repeat('=', 100) . "\n");

        foreach ($this->stats[ 'all_xpaths' ] as $xpath => $values)
        {
            fwrite
            (
                STDOUT,
        		str_pad($xpath, 76) .
        		str_pad($values[ 'f' ], 6, ' ', STR_PAD_LEFT) .
        		str_pad($values[ 'm' ], 6, ' ', STR_PAD_LEFT) .
        		str_pad($values[ 'l' ], 8, ' ', STR_PAD_LEFT) .
        		str_pad(($values[ 'b' ] ? 'yes' : 'no'), 4, ' ', STR_PAD_LEFT) .
        		"\n"
            );
        }

        fwrite(STDOUT, str_repeat('=', 100) . "\n");

        foreach ($this->stats[ 'select' ] as $key => $values)
        {
            fwrite(STDOUT, "\n" . str_repeat('=', 100) . "\n");
            fwrite(STDOUT, str_pad('Values for ' . $key, 90) . str_pad('Files', 10, ' ', STR_PAD_LEFT) . "\n");
            fwrite(STDOUT, str_repeat('=', 100) . "\n");

            ksort($values);

            foreach ($values as $value => $count)
            {
                // We're assuming UTF-8 terminal output is okay...
                fwrite(STDOUT, str_pad($value, 90) . str_pad($count, 10, ' ', STR_PAD_LEFT) . "\n");
            }

            fwrite(STDOUT, str_repeat('=', 100) . "\n");
        }
    }
}


// Execute

$script = new Digicol_Xml_Explorer();
$script->main(__FILE__, $argv);

?>
