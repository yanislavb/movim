<?php
namespace Movim\Daemon;

use Ratchet\ConnectionInterface;
use React\EventLoop\Timer\Timer;
use Movim\Controller\Front;

class Session
{
    protected   $clients;
    public      $timestamp;
    protected   $sid;
    protected   $baseuri;
    public      $process;

    public      $registered;
    public      $started;

    protected   $buffer;
    private     $state;

    private     $verbose;
    private     $debug;

    private     $language;
    private     $offset;
    protected   $path; // $path for development = "C:\php\php.exe"
    public function __construct($loop, $sid, $baseuri, $language = false, $offset = 0, $verbose = false, $debug = false)
    {
        $this->sid     = $sid;
        $this->baseuri = $baseuri;
        $this->language = $language;
        $this->offset = $offset;

        $this->verbose = $verbose;
        $this->debug = $debug;

        $this->clients = new \SplObjectStorage;
        $this->register($loop, $this);
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->path = "C:\php\php.exe"; // Don't forget to change php.exe path!
            if (!file_exists($this->path)) {die("Path doesn't set!");}
        }
        $this->timestamp = time();
    }

    public function attach($loop, ConnectionInterface $conn)
    {
        $this->clients->attach($conn);

        if($this->verbose) {
            echo colorize($this->sid, 'yellow'). " : ".colorize($conn->resourceId." connected\n", 'green');
        }

        if($this->countClients() > 0) {
            $this->stateOut('up');
        }
    }

    public function detach($loop, ConnectionInterface $conn)
    {
        $this->clients->detach($conn);

        if($this->verbose) {
            echo colorize($this->sid, 'yellow'). " : ".colorize($conn->resourceId." deconnected\n", 'red');
        }

        if($this->countClients() == 0) {
            $loop->addPeriodicTimer(20, function($timer) {
                if($this->countClients() == 0) {
                    $this->stateOut('down');
                }
                $timer->cancel();
            });
        }
    }

    public function countClients()
    {
        return $this->clients->count();
    }

    private function register($loop, $me)
    {
        $buffer = '';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') $command = $this->path." linker.php"; else $command = "exec php linker.php";
        // Launching the linker
        $this->process = new \React\ChildProcess\Process(
                            $command." ". $this->sid,
                            null,
                            [
                                'sid'       => $this->sid,
                                'baseuri'   => $this->baseuri,
                                'language'  => $this->language,
                                'offset'    => $this->offset,
                                'verbose'   => $this->verbose,
                                'debug'     => $this->debug
                            ]
                        );

        $this->process->start($loop);

        // Buffering the incoming data and fire it once its complete
        $this->process->stdout->on('data', function($output) use ($me, &$buffer) {
            if(substr($output, -1) == "") {
                $out = $buffer . substr($output, 0, -1);
                $buffer = '';
                $me->messageOut($out);
            } else {
                $buffer .= $output;
            }
        });

        // The linker died, we close properly the session
        $this->process->on('exit', function($output) use ($me) {
            if($me->verbose) {
                echo colorize($this->sid, 'yellow'). " : ".colorize("linker killed \n", 'red');
            }

            $me->process = null;
            $me->closeAll();

            $pd = new \Modl\PresenceDAO;
            $pd->clearPresence();

            $sd = new \Modl\SessionxDAO;
            $sd->delete($this->sid);
        });

        $self = $this;

        $this->process->stderr->on('data', function($output) use ($me, $self) {
            if(strpos($output, 'registered') !== false) {
                $self->registered = true;
            } elseif(strpos($output, 'started') !== false) {
                $self->started = true;
            } else {
                echo $output;
            }
        });
    }

    public function killLinker()
    {
        if(isset($this->process)) {
            $this->process->terminate();
            $this->process = null;
        }
    }

    public function closeAll()
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
    }

    public function stateOut($state)
    {
        if($this->state == $state) return;

        if(isset($this->process)) {
            $this->state = $state;
            $msg = new \stdClass;
            $msg->func = $this->state;
            $msg = json_encode($msg);
            $this->process->stdin->write($msg."");
        }
    }

    public function messageIn($msg)
    {
        $this->timestamp = time();
        if(isset($this->process)) {
            $this->process->stdin->write($msg."");
        }
        unset($msg);
    }

    public function messageOut($msg)
    {
        $this->timestamp = time();
        if(!empty($msg)) {
            foreach ($this->clients as $client) {
                $client->send($msg);
            }
        }
    }
}
