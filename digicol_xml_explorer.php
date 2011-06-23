<?php

// Licensed under the PHP License
//
// This is old code, not well-documented, and it shouldn't use 
// xml_parse_into_struct() :-)
//
// Use at your own risk!
//
// Tim Strehle <tim@digicol.de>

require_once('digicol_console_options.class.php');


class Digicol_Xml_Explorer
{
    protected $select = array();
    protected $stats = false;
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
  digicol_xml_explorer.php in conjunction with "find" or "dc_dossier.php".)

  Usage: php digicol_xml_explorer.php [ OPTIONS ] <file> [<file> ...]

    -e, --encoding <encoding>  Encoding of the input XML files:
                           "ISO-8859-1" or "UTF-8". (Default: "UTF-8")

    -s, --select <tag>     Select values of these tags or pseudo-XPath
                           expressions and list them with counts.
                           (Optional, multiple values allowed).

    -h, --help             Display this help message and exit.

  Copyright 2003-2011 by Digital Collections Verlagsgesellschaft mbH.
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
        	array( 'encoding, e' ,  1  ),
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
        	'select' => array()
    	);

        $this->select = array();

        if (isset($this->options[ 'select' ]))
        {
            $this->select = $this->options[ 'select' ];

            foreach ($this->select as $sel)
                $this->stats[ 'select' ][ $sel ] = array();
        }

        $encodings = array( 'ISO-8859-1', 'UTF-8' );

        if (! isset($this->options[ 'encoding' ]))
            $this->options[ 'encoding' ] = 'UTF-8';

        if (! in_array($this->options[ 'encoding' ], $encodings))
        {
            fwrite(STDOUT, sprintf("Wrong encoding '%s'. Must be '%s'.\n", $this->options[ 'encoding' ], implode("' or '", $encodings)));
            $this->cleanupAndExit(1);
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


    protected function mkTagInfo($key, $value, &$results)
    {
        if (! isset($results[ 'all_xpaths' ][ $key ]))
            $results[ 'all_xpaths' ][ $key ] = array( 'm' => 0, 'l' => 0, 'b' => false );

        $results[ 'all_xpaths' ][ $key ][ 'm' ]++;

        $value = trim($value);
        $l = strlen($value);

        if ($l > $results[ 'all_xpaths' ][ $key ][ 'l' ])
            $results[ 'all_xpaths' ][ $key ][ 'l' ] = $l;

        if (is_int(strpos($value, "\n")) || is_int(strpos($value, "\r")))
            $results[ 'all_xpaths' ][ $key ][ 'b' ] = true;
    }


    protected function checkXML($buffer, &$results, $filename)
    {
        $results = array
        (
            'all_tags'       => array(),
            'all_attributes' => array(),
            'all_xpaths'     => array(),
            'select' => array()
        );

        // Parse XML file

        $parser = xml_parser_create($this->options[ 'encoding' ]);

        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 0);

        $ok = xml_parse_into_struct($parser, $buffer, $tags, $index);

        if (! $ok)
        {
            $code = xml_get_error_code($parser);
            fwrite(STDERR, sprintf("\nCould not parse XML in <%s> (%s): %s on line %s\n", $filename, $code, xml_error_string($code), xml_get_current_line_number($parser)));
            xml_parser_free($parser);
            return -1;
        }

        xml_parser_free($parser);

        $levels = array();

        foreach ($tags as $tag)
        {
            // Build "XPath" string (which you can use to find out where in the hierarchy you currently are)

            $levels[ $tag[ 'level' ] ] = $tag[ 'tag' ];

            $xpath = '';

            for ($i = 1; $i <= $tag[ 'level' ]; $i++)
                $xpath .= '/' . $levels[ $i ];

            $results[ 'all_tags' ][ ] = $tag[ 'tag' ];

            // Check selected tag values

            $do_select = false;

            if (in_array($xpath, $this->select) || in_array($tag[ 'tag' ], $this->select))
                $do_select = true;

            // Build attribute string

            if (isset($tag[ 'attributes' ]))
            {
                foreach ($tag[ 'attributes' ] as $name => $value)
                {
                    $results[ 'all_attributes' ][ ] = $name;

                    if (trim($value) == '')
                    {
                        $key = $xpath . '@' . $name . ' (empty)';
                    }
                    else
                    {
                        $key = $xpath . '@' . $name;

                        if (in_array($key, $this->select))
                        {
                            if (! isset($results[ 'select' ][ $key ]))
                                $results[ 'select' ][ $key ] = array();
                                
                            $results[ 'select' ][ $key ][ ] = $value;
                        }
                    }

                    $this->mkTagInfo($key, $value, $results);
                }
            }

            // Opening tag

            if ($tag[ 'type' ] == 'open')
            {
                if (! isset($tag[ 'value' ]))
                    $tag[ 'value' ] = '';

                if (trim($tag[ 'value' ]) == '')
                {
                    $key = $xpath . ' (open)';
                    $tag[ 'value' ] = '';
                }
                else
                {
                    $key = $xpath . ' (open with value)';
                }

                $this->mkTagInfo($key, $tag[ 'value' ], $results);
            }

            // Empty or complete tag

            elseif ($tag[ 'type' ] == 'complete')
            {
                if (! isset($tag[ 'value' ]))
                    $tag[ 'value' ] = '';

                if (trim($tag[ 'value' ]) == '')
                {
                    $key = $xpath . ' (empty)';
                    $tag[ 'value' ] = '';
                }
                else
                {
                    $key = $xpath;

                    if ($do_select)
                    {
                        if (! isset($results[ 'select' ][ $key ]))
                            $results[ 'select' ][ $key ] = array();
                            
                        $results[ 'select' ][ $key ][ ] = trim($tag[ 'value' ]);
                    }
                }

                $this->mkTagInfo($key, $tag[ 'value' ], $results);
            }

            // CDATA tag (mixed or very long content, and whitespace between tags)

            elseif (($tag[ 'type' ] == 'cdata') && isset($tag[ 'value' ]))
            {
                if (trim($tag[ 'value' ]) != '')
                {
                    $key = $xpath . ' (cdata)';

                    $this->mkTagInfo($key, $tag[ 'value' ], $results);
                }
            }
        }

        foreach ($results[ 'select' ] as $key => $values)
            $results[ 'select' ][ $key ] = array_unique($results[ 'select' ][ $key ]);

        return 1;
    }


    protected function summarize()
    {
        fwrite(STDOUT, sprintf
        (
            "\n\nDone. Successfully parsed %d files. XML parse errors in %d files.\n",
            $this->stats[ 'xml_parsed' ],
            $this->stats[ 'xml_parse_error' ]
        ));

        sort($this->stats[ 'all_tags'       ]);
        sort($this->stats[ 'all_attributes' ]);
        ksort($this->stats[ 'all_xpaths'    ]);

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

        fwrite(STDOUT, "\n" . str_repeat('=', 80) . "\n");
        
        fwrite
        (
            STDOUT,
            str_pad('XPath', 56) .
            str_pad('Files', 6, ' ', STR_PAD_LEFT) .
            str_pad('Occur', 6, ' ', STR_PAD_LEFT) .
            str_pad('Length', 8, ' ', STR_PAD_LEFT) .
            str_pad('ML', 4, ' ', STR_PAD_LEFT) .
            "\n"
        );
        
        fwrite(STDOUT, str_repeat('=', 80) . "\n");

        foreach ($this->stats[ 'all_xpaths' ] as $xpath => $values)
        {
            fwrite
            (
                STDOUT,
        		str_pad($xpath, 56) .
        		str_pad($values[ 'f' ], 6, ' ', STR_PAD_LEFT) .
        		str_pad($values[ 'm' ], 6, ' ', STR_PAD_LEFT) .
        		str_pad($values[ 'l' ], 8, ' ', STR_PAD_LEFT) .
        		str_pad(($values[ 'b' ] ? 'yes' : 'no'), 4, ' ', STR_PAD_LEFT) .
        		"\n"
            );
        }

        fwrite(STDOUT, str_repeat('=', 80) . "\n");

        foreach ($this->stats[ 'select' ] as $key => $values)
        {
            fwrite(STDOUT, "\n" . str_repeat('=', 80) . "\n");
            fwrite(STDOUT, str_pad('Values for ' . $key, 70) . str_pad('Files', 10, ' ', STR_PAD_LEFT) . "\n");
            fwrite(STDOUT, str_repeat('=', 80) . "\n");

            ksort($values);

            foreach ($values as $value => $count)
            {
                // We're assuming ISO-8859-1 terminal output
                fwrite(STDOUT, str_pad(utf8_decode($value), 70) . str_pad($count, 10, ' ', STR_PAD_LEFT) . "\n");
            }

            fwrite(STDOUT, str_repeat('=', 80) . "\n");
        }
    }
}



// Execute

$script = new Digicol_Xml_Explorer();
$script->main(__FILE__, $argv);

?>
