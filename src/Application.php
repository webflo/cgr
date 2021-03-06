<?php

namespace Consolidation\Cgr;

class Application
{
    protected $outputFile = '';

    /**
     * Run the cgr tool, a safer alternative to `composer global require`.
     *
     * @param array $argv The global $argv array passed in by PHP
     * @param string $home The path to the user's home directory
     * @return integer
     */
    public function run($argv, $home)
    {
        $optionDefaultValues = $this->getDefaultOptionValues($home);
        $optionDefaultValues = $this->overlayEnvironmentValues($optionDefaultValues);

        list($argv, $options) = $this->parseOutOurOptions($argv, $optionDefaultValues);
        $commandList = $this->separateProjectAndGetCommandList($argv, $home, $options);
        return $this->runCommandList($commandList, $options);
    }

    /**
     * Set up output redirection
     */
    public function setOutputFile($outputFile)
    {
        $this->outputFile = $outputFile;
    }

    /**
     * Figure out everything we're going to do, but don't do any of it
     * yet, just return the command objects to run.
     */
    public function parseArgvAndGetCommandList($argv, $home)
    {
        $optionDefaultValues = $this->getDefaultOptionValues($home);
        $optionDefaultValues = $this->overlayEnvironmentValues($optionDefaultValues);

        list($argv, $options) = $this->parseOutOurOptions($argv, $optionDefaultValues);
        return $this->separateProjectAndGetCommandList($argv, $home, $options);
    }

    /**
     * Figure out everything we're going to do, but don't do any of it
     * yet, just return the command objects to run.
     */
    public function separateProjectAndGetCommandList($argv, $home, $options)
    {
        list($projects, $composerArgs) = $this->separateProjectsFromArgs($argv);
        $commandList = $this->getCommandStringList($composerArgs, $projects, $options);
        return $commandList;
    }

    /**
     * Run all of the commands in a list.  Abort early if any fail.
     *
     * @param array $commandList An array of CommandToExec
     * @return integer
     */
    public function runCommandList($commandList, $options)
    {
        foreach ($commandList as $command) {
            $exitCode = $command->run($this->outputFile);
            if ($exitCode) {
                return $exitCode;
            }
        }
        return 0;
    }

    /**
     * Return an array containing a list of commands to execute.  Depending on
     * the composition of the aguments and projects parameters, this list will
     * contain either a single command string to call through to composer (if
     * cgr is being used as a composer alias), or it will contain a list of
     * appropriate replacement 'composer global require' commands that install
     * each project in its own installation directory, while installing each
     * projects' binaries in the global Composer bin directory,
     * ~/.composer/vendor/bin.
     *
     * @param array $composerArgs
     * @param array $projects
     * @param array $options
     * @return CommandToExec
     */
    public function getCommandStringList($composerArgs, $projects, $options)
    {
        $command = $options['composer-path'];
        if (empty($projects)) {
            return array(new CommandToExec($command, $composerArgs));
        }
        return $this->globalRequire($command, $composerArgs, $projects, $options);
    }

    /**
     * Return our list of default option values, with paths relative to
     * the provided home directory.
     * @param string $home The user's home directory
     * @return array
     */
    public function getDefaultOptionValues($home)
    {
        return array(
            'composer-path' => 'composer',
            'base-dir' => "$home/.composer/global",
            'bin-dir' => "$home/.composer/vendor/bin",
        );
    }

    /**
     * Replace option default values with the corresponding
     * environment variable value, if it is set.
     */
    protected function overlayEnvironmentValues($defaults)
    {
        foreach ($defaults as $key => $value) {
            $envKey = 'CGR_' . strtoupper(strtr($key, '-', '_'));
            $envValue = getenv($envKey);
            if ($envValue) {
                $defaults[$key] = $envValue;
            }
        }

        return $defaults;
    }

    /**
     * We use our own special-purpose argv parser. The options that apply
     * to this tool are identified by a simple associative array, where
     * the key is the option name, and the value is its default value.
     * The result of this function is an array of two items containing:
     *  - An array of the items in $argv not used to set an option value
     *  - An array of options containing the user-specified or default values
     *
     * @param array $argv The global $argv passed in by php
     * @param array $optionDefaultValues An associative array
     * @return array
     */
    public function parseOutOurOptions($argv, $optionDefaultValues)
    {
        array_shift($argv);
        $passAlongArgvItems = array();
        $options = array();
        while (!empty($argv)) {
            $arg = array_shift($argv);
            if ((substr($arg, 0, 2) == '--') && array_key_exists(substr($arg, 2), $optionDefaultValues)) {
                $options[substr($arg, 2)] = array_shift($argv);
            } else {
                $passAlongArgvItems[] = $arg;
            }
        }
        return array($passAlongArgvItems, $options + $optionDefaultValues);
    }

    /**
     * After our options are removed by parseOutOurOptions, those items remaining
     * in $argv will be separated into a list of projects and versions, and
     * anything else that is not a project:version. Returns an array of two
     * items containing:
     *  - An associative array, where the key is the project name and the value
     *    is the version (or an empty string, if no version was specified)
     *  - The remaining $argv items not used to build the projects array.
     *
     * @param array $argv The $argv array from parseOutOurOptions()
     * @return array
     */
    public function separateProjectsFromArgs($argv)
    {
        $composerArgs = array();
        $projects = array();
        $sawGlobal = false;
        foreach ($argv as $arg) {
            if ($arg[0] == '-') {
                // Any flags (first character is '-') will just be passed
                // through to to composer. Flags interpreted by cgr have
                // already been removed from $argv.
                $composerArgs[] = $arg;
            } elseif (strpos($arg, '/') !== false) {
                // Arguments containing a '/' name projects.  We will split
                // the project from its version, allowing the separator
                // character to be either a '=' or a ':', and then store the
                // result in the $projects array.
                $projectAndVersion = explode(':', strtr($arg, '=', ':'), 2) + array('', '');
                list($project, $version) = $projectAndVersion;
                $projects[$project] = $version;
            } elseif ($this->isComposerVersion($arg)) {
                // If an argument is a composer version, then we will alter
                // the last project we saw, attaching this version to it.
                // This allows us to handle 'a/b:1.0' and 'a/b 1.0' equivalently.
                $keys = array_keys($projects);
                $lastProject = array_pop($keys);
                unset($projects[$lastProject]);
                $projects[$lastProject] = $arg;
            } elseif ($arg == 'global') {
                // Make note if we see the 'global' command.
                $sawGlobal = true;
            } else {
                // If we see any command other than 'global require',
                // then we will pass *all* of the arguments through to
                // composer unchanged. We return an empty projects array
                // to indicate that this should be a pass-through call
                // to composer, rather than one or more calls to
                // 'composer require' to install global projects.
                if ((!$sawGlobal) || ($arg != 'require')) {
                    return array(array(), $argv);
                }
            }
        }
        return array($projects, $composerArgs);
    }

    /**
     * Provide a safer version of `composer global require`.  Each project
     * listed in $projects will be installed into its own project directory.
     * The binaries from each project will still be placed in the global
     * composer bin directory.
     *
     * @param string $command The path to composer
     * @param array $composerArgs Anything from the global $argv to be passed
     *   on to Composer
     * @param array $projects A list of projects to install, with the key
     *   specifying the project name, and the value specifying its version.
     * @param array $options User options from the command line; see
     *   $optionDefaultValues in the main() function.
     * @return array
     */
    public function globalRequire($command, $composerArgs, $projects, $options)
    {
        $globalBaseDir = $options['base-dir'];
        $binDir = $options['bin-dir'];
        $env = array("COMPOSER_BIN_DIR" => $binDir);
        $result = array();
        foreach ($projects as $project => $version) {
            $installLocation = "$globalBaseDir/$project";
            $projectWithVersion = $this->projectWithVersion($project, $version);
            $commandToExec = $this->globalRequireOne($command, $composerArgs, $projectWithVersion, $env, $installLocation);
            $result[] = $commandToExec;
        }
        return $result;
    }

    /**
     * Return $project:$version, or just $project if there is no $version.
     *
     * @param string $project The project to install
     * @param string $version The version desired
     * @return string
     */
    public function projectWithVersion($project, $version)
    {
        if (empty($version)) {
            return $project;
        }
        return "$project:$version";
    }

    /**
     * Generate command string to call `composer require` to install one project.
     *
     * @param string $command The path to composer
     * @param array $composerArgs The arguments to pass to composer
     * @param string $projectWithVersion The project:version to install
     * @param array $env Environment to set prior to exec
     * @param string $installLocation Location to install the project
     * @return CommandToExec
     */
    public function globalRequireOne($command, $composerArgs, $projectWithVersion, $env, $installLocation)
    {
        $projectSpecificArgs = array("--working-dir=$installLocation", 'require', $projectWithVersion);
        $arguments = array_merge($composerArgs, $projectSpecificArgs);
        return new CommandToExec($command, $arguments, $env, $installLocation);
    }

    /**
     * Identify an argument that could be a Composer version string.
     *
     * @param string $arg The argument to test
     * @return boolean
     */
    public function isComposerVersion($arg)
    {
        $specialVersionChars = array('^', '~', '<', '>');
        return is_numeric($arg[0]) || in_array($arg[0], $specialVersionChars);
    }
}
