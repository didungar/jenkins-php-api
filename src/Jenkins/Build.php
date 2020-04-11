<?php

namespace DidUngar\Jenkins;

use DidUngar\Jenkins;

class Build
{

    /**
     * @var string
     */
    const FAILURE = 'FAILURE';

    /**
     * @var string
     */
    const SUCCESS = 'SUCCESS';

    /**
     * @var string
     */
    const RUNNING = 'RUNNING';

    /**
     * @var string
     */
    const WAITING = 'WAITING';

    /**
     * @var string
     */
    const UNSTABLE = 'UNSTABLE';

    /**
     * @var string
     */
    const ABORTED = 'ABORTED';

    /**
     * @var string
     */
    const API_ARG_PRETTY = 'pretty=true';

    /**
     * @var array
     */
    const API_ARGS_FULL = [self::API_ARG_PRETTY];

    /**
     * @var string
     */
    const API_ARG_TREE_SMALL = 'tree=actions[parameters,parameters[name,value]],result,duration,timestamp,number,url,estimatedDuration,builtOn';

    /**
     * @var \stdClass
     */
    private $build;
    /**
     * @var Jenkins
     */
    private $jenkins;

    /**
     * @param \stdClass $build
     * @param Jenkins   $jenkins
     */
    public function __construct($build, Jenkins $jenkins)
    {
        $this->build = $build;
        $this->setJenkins($jenkins);
    }

    /**
     * @return array
     */
    public function getInputParameters()
    {
        $parameters = [];

        if (!property_exists($this->build->actions[0], 'parameters')) {
            return $parameters;
        }

        foreach ($this->build->actions[0]->parameters as $parameter) {
            $parameters[ $parameter->name ] = $parameter->value;
        }

        return $parameters;
    }

    /**
     * @return int
     */
    public function getDuration()
    {
        //division par 1000 => pas de millisecondes
        return $this->build->duration / 1000;
    }

    public function getNumber(): int
    {
        return $this->build->number;
    }

    public function getBuildData(): ?\stdClass
    {
        return $this->findActionByClass('hudson.plugins.git.util.BuildData');
    }
    public function getLastBuildRevision(): ?\stdClass
    {
        $branch = $this->findActionByClass('hudson.plugins.git.util.BuildData');
        if ($branch) {
            return $branch->lastBuiltRevision;
        }
        return null;
    }

    protected function findActionByClass(string $class)
    {
        foreach ($this->getActions() as $action) {
            if (!isset($action->_class)) {
                continue;
            }
            if ($class === $action->_class) {
                return $action;
            }
        }

        return null;
    }

    public function getActions(): array
    {
        return $this->build->actions;
    }

    public function getCauseActionUseridCause(): ?\stdClass
    {
        $action = $this->getCauseAction();
        if (!$action) {
            return null;
        }
        foreach ($action->causes as $cause) {
            if ('hudson.model.Cause$UserIdCause' == $cause->_class) {
                return $cause;
            }
        }

        return null;
    }

    public function getCauseAction(): ?\stdClass
    {
        return $this->findActionByClass('hudson.model.CauseAction');
    }

    public function getCauseActionTimerTriggerCause(): ?\stdClass
    {
        $action = $this->getCauseAction();
        if (!$action) {
            return null;
        }
        foreach ($action->causes as $cause) {
            if ('hudson.triggers.TimerTrigger$TimerTriggerCause' == $cause->_class) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * Returns remaining execution time (seconds)
     *
     * @return int|null
     */
    public function getRemainingExecutionTime()
    {
        $remaining = null;
        if (null !== ($estimatedDuration = $this->getEstimatedDuration())) {
            //be carefull because time from JK server could be different
            //of time from Jenkins server
            //but i didn't find a timestamp given by Jenkins api

            $remaining = $estimatedDuration - (time() - $this->getTimestamp());
        }

        return max(0, $remaining);
    }

    /**
     * @return float|null
     */
    public function getEstimatedDuration()
    {
        //since version 1.461 estimatedDuration is displayed in jenkins's api
        //we can use it witch is more accurate than calcule ourselves
        //but older versions need to continue to work, so in case of estimated
        //duration is not found we fallback to calcule it.
        if (property_exists($this->build, 'estimatedDuration')) {
            return $this->build->estimatedDuration / 1000;
        }

        $duration = null;
        $progress = $this->getProgress();
        if (null !== $progress && $progress >= 0) {
            $duration = ceil((time() - $this->getTimestamp()) / ($progress / 100));
        }

        return $duration;
    }

    /**
     * @return null|int
     */
    public function getProgress()
    {
        $progress = null;
        if (null !== ($executor = $this->getExecutor())) {
            $progress = $executor->getProgress();
        }

        return $progress;
    }

    /**
     * @return Executor|null
     */
    public function getExecutor()
    {
        if (!$this->isRunning()) {
            return null;
        }

        $runExecutor = null;
        foreach ($this->getJenkins()->getExecutors() as $executor) {
            /** @var Executor $executor */

            if ($this->getUrl() === $executor->getBuildUrl()) {
                $runExecutor = $executor;
            }
        }

        return $runExecutor;
    }

    /**
     * @return bool
     */
    public function isRunning()
    {
        return Build::RUNNING === $this->getResult();
    }

    /**
     * @return null|string
     */
    public function getResult()
    {
        $result = null;
        switch ($this->build->result) {
            case 'FAILURE':
                $result = Build::FAILURE;
                break;
            case 'SUCCESS':
                $result = Build::SUCCESS;
                break;
            case 'UNSTABLE':
                $result = Build::UNSTABLE;
                break;
            case 'ABORTED':
                $result = Build::ABORTED;
                break;
            case 'WAITING':
                $result = Build::WAITING;
                break;
            default:
                $result = Build::RUNNING;
                break;
        }

        return $result;
    }

    /**
     * @return Jenkins
     */
    public function getJenkins()
    {
        return $this->jenkins;
    }

    /**
     * @param Jenkins $jenkins
     *
     * @return Job
     */
    public function setJenkins(Jenkins $jenkins)
    {
        $this->jenkins = $jenkins;

        return $this;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->build->url;
    }

    public function getTimestamp() :float
    {
        //division par 1000 => pas de millisecondes
        return (float)($this->build->timestamp / 1000);
    }

    public function getDateTime() : \DateTime
    {
        $when = new \DateTime();
        $when->setTimestamp($this->getTimestamp());
        return $when;
    }

    public function getBuiltOn()
    {
        return $this->build->builtOn;
    }
}
