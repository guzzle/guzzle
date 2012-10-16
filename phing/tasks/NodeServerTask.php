<?php
/**
 * Phing task for node server checking
 *
 * @copyright 2012 Clay Loveless <clay@php.net>
 * @license   http://claylo.mit-license.org/2012/ MIT License
 */

require_once 'phing/Task.php';

class NodeServerTask extends Task
{
    protected $cmd = null;
    protected $action = 'start';
    protected $serverfile = 'tests/Guzzle/Tests/Http/server.js';

    /**
     * The setter for the start command
     *
     * @param string $str How to start the node server
     */
    public function setCmd($str)
    {
        $this->cmd = $str;
    }

    public function getCmd()
    {
        return $this->cmd;
    }

    /**
     * The setter for the action
     *
     * @param string $str Start up or shutdown
     */
    public function setAction($str)
    {
        $this->action = $str;
    }
    public function getAction()
    {
        return $this->action;
    }

    public function main()
    {
        $cmd = $this->getCmd();
        $action = $this->getAction();

        if (empty($cmd)) {
            throw new BuildException('"cmd" is a required parameter');
        }

        if ($action == 'start') {
            $this->startServer();
        } else {
            $this->stopServer();
        }
    }

    protected function startServer()
    {
        chdir(__DIR__ . '/../..');
        $serverfile = $this->serverfile;
        $cmd = $this->getCmd();

        // resolve $HOME directory
        if ($cmd[0] == '~') {
            $cmd = $_ENV["HOME"] . substr($cmd, 1);
        }

        $fp = @fsockopen('127.0.0.1', 8124, $errno, $errstr, 1);
        if (! $fp) {
            // need to start node server
            $cmd = escapeshellcmd($cmd . ' ' . $serverfile);
            $this->log('starting node test server with '.$cmd);
            exec($cmd . ' &> /dev/null &');
            sleep(2);
            $fp = @fsockopen('127.0.0.1', 8124, $errno, $errstr, 1);
        }

        // test it again
        if (! $fp) {
            $this->log('could not start node server');
        } else {
            fclose($fp);
            $this->log('node test server running');
        }
    }

    protected function stopServer()
    {
        exec('ps axo "pid,command"', $out);
        $nodeproc = false;
        foreach ($out as $proc) {
            if (strpos($proc, $this->serverfile) !== false) {
                $nodeproc = $proc;
                break;
            }
        }

        if ($nodeproc) {
            $proc = trim($nodeproc);
            $space = strpos($proc, ' ');
            $pid = substr($proc, 0, $space);

            $killed = posix_kill($pid, 9);
            if ($killed) {
                $this->log('test server stopped');
            } else {
                $this->log('test server appears immortal');
            }
        } else {
            $this->log('could not find test server in process list');
        }
    }
}
