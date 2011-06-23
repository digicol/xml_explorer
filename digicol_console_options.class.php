<?php

// Licensed under the PHP License
//
// Use at your own risk!
//
// Tim Strehle <tim@digicol.de>

/**
 * Console options class
 */
 
class Digicol_Console_Options
{
    protected $config;
    protected $options;
    protected $values;
    
 
    /**
     * Constructor
     *
     * @param array|false $config
     */
     
    public function __construct($config = false)
    {
        if ($config !== false)
            $this->define($config);
    }
    
    
    /**
     * Define options
     *
     * @param array $config
     */
     
    public function define($config)
    {
        $this->config = $config;        
        
        // Get option names
        // An option is defined like this:
        // array( '<comma-separated list of names>', '<argument count>' )
        //   <argument count> can be 0 or a positive integer, or:
        //   "?" = 0 or 1
        //   "*" = 0 or more
        //   "+" = 1 or more

        $this->options = array();

        foreach ($this->config as $key => $conf)
        {
            $names = array_map('trim', explode(',', $conf[ 0 ]));

            foreach ($names as $name)
            {
                if ($name == '')
                    continue;

                $prefix = (strlen($name) == 1 ? '-' : '--');
                $this->options[ ($prefix . $name) ] = $key;
            }

            // Use the first name in the list as the key for $result

            $this->config[ $key ][ 2 ] = $names[ 0 ];
        }
    }


    /**
     * Parse arguments
     *
     * @param array $args
     * @return array
     */
     
    public function parse($args, $check_options = false)
    {
        if (! (is_array($args) && is_array($this->config)))
            return false;

        $this->values = array( '_' => array() );


        // Skip the first argument, it usually is the file name of the PHP script

        if (isset($args[ 0 ]))
        {
            if (isset($args[ 0 ]{ 0 }) && ($args[ 0 ]{ 0 } != '-'))
                array_shift($args);
        }

        if (count($args) == 0)
            return $this->getAllValues();


        // Parse arguments

        $lastopt = '';

        foreach ($args as $arg)
        {
            $arg = trim($arg);

            if ($arg == '')
                continue;


            // Special case: -- stops option scanning, the rest of the arguments
            // goes into "_"

            if ($lastopt == '--')
            {
                $this->values[ '_' ][ ] = $arg;
                continue;
            }

            if ($arg == '--')
            {
                $lastopt = '--';
                continue;
            }


            // Is this an option name?

            if ($arg{ 0 } == '-')
            {
                if (($arg != '-') && ($arg != '--'))
                {
                    $lastopt = '';

                    // Special case: --option=value

                    if (strpos($arg, '=') > 0)
                    {
                        list($optname, $value) = explode('=', $arg, 2);

                        if (isset($this->options[ $optname ]))
                        {
                            $this->addValue($optname, $value);
                        }
                        else
                        {
                            if ($check_options)
                                return $optname;
                        }

                        continue;
                    }

                    // Special case: -<multiple short options>

                    if (preg_match('/^-([a-zA-Z]{2,60})$/', $arg, $matches))
                    {
                        for ($i = 0; $i < strlen($matches[ 1 ]); $i++)
                        {
                            $optname = '-' . $matches[ 1 ]{ $i };

                            if (isset($this->options[ $optname ]))
                            {
                                $this->addValue($optname, true);
                            }
                            else
                            {
                                if ($check_options)
                                    return $optname;
                            }
                        }

                        continue;
                    }

                    $optname = $arg;

                    if (isset($this->options[ $optname ]))
                    {
                        // For no-value options (or where values are optional),
                        // set value to true

                        $type = $this->config[ $this->options[ $optname ] ][ 1 ];

                        if (($type === 0) || ($type === '?') || ($type === '*'))
                        {
                            $this->addValue($optname, true);

                            if (($type === '?') || ($type === '*'))
                                $lastopt = $optname;
                        }

                        // For options with values, remember option name for
                        // following arguments

                        else
                        {
                            $lastopt = $optname;
                        }
                    }
                    else
                    {
                        if ($check_options)
                            return $optname;
                    }

                    continue;
                }
            }

            if ($lastopt == '')
            {
                $this->values[ '_' ][ ] = $arg;
                continue;
            }

            // Try to add to the last active option

            $ok = $this->addValue($lastopt, $arg);

            // If adding failed (probably because the limit of arguments for
            // that option has been reached), add to non-option argument list

            if ($ok < 1)
                $this->values[ '_' ][ ] = $arg;
        }


        // Validation: Remove options that don't have enough values

        foreach ($this->config as $conf)
        {
            if (($conf[ 1 ] > 1) && isset($this->values[ $conf[ 2 ] ]))
            {
                if (count($this->values[ $conf[ 2 ] ]) != $conf[ 1 ])
                    unset($this->values[ $conf[ 2 ] ]);
            }
        }

        return $this->getAllValues();
    }


    /**
     * Add option value
     *
     * @param string $option
     * @param string $value
     * @return int
     */
     
    private function addValue($option, $value)
    {
        $type = $this->config[ $this->options[ $option ] ][ 1 ];
        $key  = $this->config[ $this->options[ $option ] ][ 2 ];

        // Set 0/1 options only once

        if (($type === 0) || ($type === 1))
        {
            if (isset($this->values[ $key ]))
                return 0;
        }

        // Set ? option once, override previous "true" value

        if (($type == '?') && isset($this->values[ $key ]))
        {
            if ($this->values[ $key ] === true)
            {
                $this->values[ $key ] = $value;
                return 1;
            }
            else
            {
                return 0;
            }
        }

        // Force 0 options to have a "true" value

        if ($type === 0)
        {
            $value = true;
        }

        // Block "true" values for all other option types

        elseif (($value === true) && ($type !== '*') && ($type !== '?'))
        {
            return 0;
        }

        // */+/>1 options will have an array of values

        if (($type > 1) || ($type === '*') || ($type === '+'))
        {
            if (! isset($this->values[ $key ]))
                $this->values[ $key ] = array();

            // >1 options limit check

            if ($type > 1)
            {
                if (count($this->values[ $key ]) >= $type)
                    return -1;
            }

            if ($type === '*')
            {
                // Do not add a "true" value to * options which already have a
                // "real" value

                if (($value === true) && (count($this->values[ $key ]) > 0))
                    return 0;

                // * options may have a "true" value already, which becomes
                // meaningless once there's a real value

                if (($value !== true) && (count($this->values[ $key ]) == 1))
                {
                    if ($this->values[ $key ][ 0 ] === true)
                        array_shift($this->values[ $key ]);
                }
            }

	        $this->values[ $key ][ ] = $value;
        }
        else
        {
            $this->values[ $key ] = $value;
        }

        return 1;
    }


    /**
     * Get all values
     *
     * @return array
     */
     
    public function getAllValues()
    {
        return $this->values;
    }
    
    
    /**
     * Get value
     *
     * @param string $option
     * @return mixed
     */
     
    public function getValue($option)
    {
        if (! isset($this->values[ $option ]))
            return false;

        return $this->values[ $option ];
    }
    
    
    /**
     * Check whether option is set
     *
     * @param string $option
     * @return bool
     */
     
    public function hasValue($option)
    {
        return (isset($this->values[ $option ]));
    }
}


?>
