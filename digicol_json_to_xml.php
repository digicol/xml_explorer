<?php

// Licensed under the PHP License
//
// This is experimental code, not well-documented...
// Use at your own risk!
//
// Tim Strehle <tim@digicol.de>

require_once('digicol_console_options.class.php');


class Digicol_Json_To_Xml
{
    protected $exit_on_unknown_options = true;
    
    
    public function getHelpText()
    {
        return <<<EOT

  Convert JSON to XML.

  Simple JSON to XML conversion. JSON property names not allowed as XML tag names
  will be represented as <_tag name="property name">.

  Set <file> to "-" to read multiple file names or a single JSON object from STDIN. 
  (This allows you to use digicol_json_to_xml.php in conjunction with "find".)

  Usage: php digicol_json_to_xml.php [ OPTIONS ] <file> [<file> ...]

    -h, --help             Display this help message and exit.

  Copyright 2013 by Digital Collections Verlagsgesellschaft mbH.
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
            $this->action = 'convert';
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


    protected function executeAction_convert()
    {
        // Read from STDIN?

        if ($this->options[ '_' ][ 0 ] === '-')
        {
            $first = true;
            $mode = 'filename';
            $buffer = '';

            while (! feof(STDIN))
            {
                $line = fgets(STDIN);

                // Check input - if the first line doesn't look like XML, we're in filename mode
                // XXX rather dumb check: look for "{" and test whether this is an existing file

                if ($first)
                {
                    if (strpos($line, '{') !== false)
                        $mode = 'buffer';
                }

                if ($mode === 'buffer')
                {
                    $buffer .= $line;
                }
                elseif ($line != '')
                {
                    $this->processFile($line, 'filename');
                }

                $first = false;
            }

            if ($mode === 'buffer')
            {
                if ($buffer !== '')
                    $this->processFile($buffer, 'buffer');
            }
        }
        else
        {
            foreach ($this->options[ '_' ] as $filename)
                $this->processFile($filename, 'filename');
        }
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

        if ($mode == 'filename')
        {
            $ok = $this->convertToXml(file_get_contents($filename), $filename);
        }
        elseif ($mode == 'buffer')
        {
            $ok = $this->convertToXml($filename, 'stdin');
        }
    }


    protected function convertToXml($buffer, $filename)
    {
        fwrite(STDOUT, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");        
        fwrite(STDOUT, $this->jsonToXml('json', json_decode($buffer, true), 0));
        
        return 1;        
    }


    protected function jsonToXml($tag, $data, $level)
    {
        $result = '';
    
        $this->makeTag($tag, $open_tag, $close_tag);

        if (! is_array($data))
        {
            // Convert boolean values to 0/1
        
            if ($data === true)
                $data = '1';
        
            if ($data === false)
                $data = '0';
        
            if (strlen($data) === 0)
                return sprintf("%s<%s/>\n", str_repeat(' ', $level * 2), $open_tag);        

            $result .= sprintf
            (
                "%s<%s>%s</%s>\n", 
                str_repeat(' ', $level * 2), 
                $open_tag, 
                htmlspecialchars($data), 
                $close_tag
            );

            return $result;        
        }
    
        $keys = array_keys($data);
    
        // Empty array
    
        if (count($keys) === 0)
            return sprintf("%s<%s/>\n", str_repeat(' ', $level * 2), $open_tag);
        
        // Dumb numeric array detection
    
        if (is_numeric($keys[ 0 ]))
        {
            foreach ($keys as $key)
                $result .= $this->jsonToXml($tag, $data[ $key ], $level);
        }
        else
        {
            $result .= sprintf("%s<%s>\n", str_repeat(' ', $level * 2), $open_tag);
        
            foreach ($keys as $key)
                $result .= $this->jsonToXml($key, $data[ $key ], $level + 1);
            
            $result .= sprintf("%s</%s>\n", str_repeat(' ', $level * 2), $close_tag);
        }
    
        return $result;
    }


    protected function makeTag($tag, &$open_tag, &$close_tag)
    {
        $open_tag = $close_tag = $tag;
        
        if (! $this->isValidTagName($tag))
        {
            $open_tag  = sprintf('_tag name="%s"', htmlspecialchars($tag));
            $close_tag = '_tag';
        }
    }
    
    
    /**
     * Check whether a string is a valid XML tag name
     *
     * Checks whether the given string is a valid XML tag name. The rules are basically:
     * - no whitespace
     * - no special characters, only A-Z, 0-9, ".", ":", "-" and "_"
     * - must not begin with a number, ".", or "-"
     * - must not begin with "xml"
     *
     * This validity check does not comply fully with the XML 1.0 specification in that it forbids umlauts
     * although they should be allowed.
     *
     * @param string $tagname String to check
     * @return bool True or false
     */

    protected function isValidTagName($str)
    {
        if (! preg_match('/^[a-z_:]+[a-z0-9._:-]*$/i', $str))
            return false;

        if (strtolower(substr($str, 0, 3)) == 'xml')
            return false;

        return true;
    }
}


// Execute

$script = new Digicol_Json_To_Xml();
$script->main(__FILE__, $argv);

?>
