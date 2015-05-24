<?php

namespace josegonzalez\Dotenv;

use InvalidArgumentException;
use josegonzalez\Dotenv\Expect;
use josegonzalez\Dotenv\Parser;
use LogicException;

class Loader
{

    protected $environment = null;

    protected $filepaths = null;

    protected $prefix = null;

    protected $raise = true;

    protected $skip = array(
        'define' => false,
        'toEnv' => false,
        'toServer' => false,
        'putenv' => false
    );

    public function __construct($filepaths = null)
    {
        $this->setFilepaths($filepaths);
        return $this;
    }

    public static function load($options = null)
    {
        $filepath = null;
        if (is_string($options)) {
            $filepath = $options;
            $options = array();
        } elseif (isset($options['filepath'])) {
            $filepath = $options['filepath'];
            unset($options['filepath']);
        }

        $dotenv = new \josegonzalez\Dotenv\Loader($filepath);

        if (array_key_exists('raiseExceptions', $options)) {
            $dotenv->raiseExceptions($options['raiseExceptions']);
        }

        $dotenv->parse();

        $methods = array(
            'skipExisting',
            'prefix',
            'expect',
            'define',
            'toEnv',
            'toServer',
            'putenv',
        );
        foreach ($methods as $method) {
            if (array_key_exists($method, $options)) {
                $dotenv->$method($options[$method]);
            }
        }

        return $dotenv;
    }

    public function filepath()
    {
        return current($this->filepaths);
    }

    public function filepaths()
    {
        return $this->filepaths;
    }

    public function setFilepath($filepath = null)
    {
        return $this->setFilepaths($filepath);
    }

    public function setFilepaths($filepaths = null)
    {
        if ($filepaths == null) {
            $filepaths = [__DIR__ . DIRECTORY_SEPARATOR . '.env'];
        }

        if (is_string($filepaths)) {
            $filepaths = [$filepaths];
        }

        $this->filepaths = $filepaths;
        return $this;
    }

    public function parse()
    {
        $contents = false;
        $filepaths = $this->filepaths();

        foreach ($filepaths as $filepath) {
            if (!file_exists($filepath)) {
                return $this->raise(
                    'InvalidArgumentException',
                    sprintf("Environment file '%s' is not found", $filepath)
                );
            }

            if (is_dir($filepath)) {
                return $this->raise(
                    'InvalidArgumentException',
                    sprintf("Environment file '%s' is a directory. Should be a file", $filepath)
                );
            }

            if (!is_readable($filepath) || ($contents = file_get_contents($filepath)) === false) {
                return $this->raise(
                    'InvalidArgumentException',
                    sprintf("Environment file '%s' is not readable", $filepath)
                );
            }

            if ($contents !== false) {
                break;
            }
        }

        $parser = new Parser;
        $this->environment = $parser->parse($contents);

        return $this;
    }

    public function expect()
    {
        $this->requireParse('expect');

        $expect = new Expect($this->environment, $this->raise);
        call_user_func_array($expect, func_get_args());

        return $this;
    }

    public function define()
    {
        $this->requireParse('define');
        foreach ($this->environment as $key => $value) {
            $prefixedKey = $this->prefixed($key);
            if (defined($prefixedKey)) {
                if ($this->skip['define']) {
                    continue;
                }

                return $this->raise(
                    'LogicException',
                    sprintf('Key "%s" has already been defined', $prefixedKey)
                );
            }

            define($prefixedKey, $value);
        }

        return $this;
    }

    public function toEnv($overwrite = false)
    {
        $this->requireParse('toEnv');
        foreach ($this->environment as $key => $value) {
            $prefixedKey = $this->prefixed($key);
            if (isset($_ENV[$prefixedKey]) && !$overwrite) {
                if ($this->skip['toEnv']) {
                    continue;
                }

                return $this->raise(
                    'LogicException',
                    sprintf('Key "%s" has already been defined in $_ENV', $prefixedKey)
                );
            }

            $_ENV[$prefixedKey] = $value;
        }

        return $this;
    }

    public function putenv($overwrite = false)
    {
        $this->requireParse('putenv');
        foreach ($this->environment as $key => $value) {
            $prefixedKey = $this->prefixed($key);
            if (getenv($prefixedKey) && !$overwrite) {
                if ($this->skip['putenv']) {
                    continue;
                }

                return $this->raise(
                    'LogicException',
                    sprintf('Key "%s" has already been defined in getenv()', $prefixedKey)
                );
            }

            putenv($prefixedKey . '=' . $value);
        }

        return $this;
    }

    public function toServer($overwrite = false)
    {
        $this->requireParse('toServer');
        foreach ($this->environment as $key => $value) {
            $prefixedKey = $this->prefixed($key);
            if (isset($_SERVER[$prefixedKey]) && !$overwrite) {
                if ($this->skip['toServer']) {
                    continue;
                }

                return $this->raise(
                    'LogicException',
                    sprintf('Key "%s" has already been defined in $_SERVER', $prefixedKey)
                );
            }

            $_SERVER[$prefixedKey] = $value;
        }

        return $this;
    }

    public function skipExisting($types = null)
    {
        $args = func_get_args();
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }

        $types = (array)$args;
        if (empty($types)) {
            $types = array_keys($this->skip);
        }

        foreach ((array)$types as $type) {
            $this->skip[$type] = true;
        }

        return $this;
    }

    public function skipped()
    {
        $skipped = array();
        foreach ($this->skip as $key => $value) {
            if ($value == true) {
                $skipped[] = $key;
            }
        }
        return $skipped;
    }

    public function prefix($prefix = null)
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function prefixed($key)
    {
        if (!!$this->prefix) {
            $key = $this->prefix . $key;
        }

        return $key;
    }

    public function raiseExceptions($raise = true)
    {
        $this->raise = $raise;
        return $this;
    }

    public function toArray()
    {
        $this->requireParse('toArray');
        if ($this->environment === null) {
            return null;
        }

        $environment = array();
        foreach ($this->environment as $key => $value) {
            $environment[$this->prefixed($key)] = $value;

        }
        return $environment;
    }

    public function __toString()
    {
        try {
            $data = $this->toArray();
        } catch (LogicException $e) {
            $data = array();
        }

        return json_encode($data);
    }

    protected function requireParse($method)
    {
        if (!is_array($this->environment)) {
            return $this->raise(
                'LogicException',
                sprintf('Environment must be parsed before calling %s()', $method)
            );
        }
    }

    protected function raise($exception, $message)
    {
        if ($this->raise) {
            throw new $exception($message);
        }

        return false;
    }
}
