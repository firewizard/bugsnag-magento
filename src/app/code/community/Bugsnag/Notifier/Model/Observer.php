<?php

class Bugsnag_Notifier_Model_Observer
{
    private static $DEFAULT_NOTIFY_SEVERITIES = "error";

    private static $NOTIFIER = array(
        "name" => "Bugsnag Magento (Official)",
        "version" => "1.0.0",
        "url" => "https://bugsnag.com/notifiers/magento"
    );

    /** @var Bugsnag_Client */
    private $client;
    private $apiKey;
    private $notifySeverities;
    private $filterFields;
    protected $environment;

    public function fireTestEvent($apiKey)
    {
        if (strlen($apiKey) != 32) {
            throw new Exception("Invalid length of the API key");
        }

        $client = new Bugsnag_Client($apiKey);
        $client->notifyError(
            "BugsnagTest",
            "Testing bugsnag",
            array("notifier" => self::$NOTIFIER)
        );
    }

    public function initBugsnag()
    {
        if (file_exists(Mage::getBaseDir('lib') . '/bugsnag-php/Autoload.php')) {
            require_once(Mage::getBaseDir('lib') . '/bugsnag-php/Autoload.php');
        } else {
            error_log("Bugsnag Error: Couldn't activate Bugsnag Error Monitoring due to missing Bugsnag PHP library!");
            return;
        }

        $this->apiKey = Mage::getStoreConfig("dev/Bugsnag_Notifier/apiKey");
        $this->notifySeverities = Mage::getStoreConfig("dev/Bugsnag_Notifier/severities");
        $this->filterFields = Mage::getStoreConfig("dev/Bugsnag_Notifier/filterFields");
        $this->environment = trim(Mage::getStoreConfig("dev/Bugsnag_Notifier/environment"));

        // Activate the bugsnag client
        if (!empty($this->apiKey)) {
            $this->client = new Bugsnag_Client($this->apiKey);

            $this->client
                ->setProjectRoot(Mage::getBaseDir() . DS)
                ->setReleaseStage($this->releaseStage())
                ->setErrorReportingLevel($this->errorReportingLevel())
                ->setFilters($this->filterFields());

            $this->client->setNotifier(self::$NOTIFIER);

            if (Mage::getSingleton('customer/session')->isLoggedIn()) {
                $this->addUserTab();
            }

            set_error_handler(array($this->client, "errorHandler"));
            set_exception_handler(array($this->client, "exceptionHandler"));
        }
    }

    private function addUserTab()
    {
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $this->client->setUser(array(
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'created_at' => $customer->getCreatedAt(),
            'first_name' => $customer->getFirstname(),
            'last_name' => $customer->getLastname()
        ));
    }

    private function releaseStage()
    {
        if (!is_null($this->environment)) {
            return $this->environment;
        }
        return Mage::getIsDeveloperMode() ? "development" : "production";
    }

    private function errorReportingLevel()
    {
        if (empty($this->notifySeverities)) {
            $notifySeverities = static::$DEFAULT_NOTIFY_SEVERITIES;
        } else {
            $notifySeverities = $this->notifySeverities;
        }

        $level = 0;
        $severities = explode(",", $notifySeverities);

        foreach ($severities as $severity) {
            $level |= Bugsnag_ErrorTypes::getLevelsForSeverity($severity);
        }

        return $level;
    }

    private function filterFields()
    {
        return array_map('trim', explode("\n", $this->filterFields));
    }

    /**
     * @param mixed $message
     * @param null|string $severity
     * @return bool
     */
    public function sendCustomMessage($message, $severity = null)
    {
        // only init once
        if (!$this->client) {
            $this->initBugsnag();
        }

        // bail out, bugsnag probably not used
        if (!$this->client) {
            return false;
        }

        // send only specified severities
        $notifyOn = empty($this->notifySeverities) ?
            static::$DEFAULT_NOTIFY_SEVERITIES :
            $this->notifySeverities;
        $notifyOn = array_map('trim', explode(',', $notifyOn));

        if (!in_array($severity, $notifyOn)) {
            return false;
        }

        // when logging magento logs to bugsnag, the last 3 entries in the call stack
        // will always be Mage, Zend_Log and Zend_Log_Writer_Abstract.
        // filtering these out for a cleaner backtrace
        $this->client->setBeforeNotifyFunction(function ($error) {
            if (empty($error->stacktrace->frames)) {
                return;
            }

            $ignoredTraces = array(
                'Zend_Log_Writer_Abstract::write' => 0,
                'Zend_Log::log' => 1,
                'Mage::log' => 2,
            );
            foreach ($error->stacktrace->frames as $k => $data) {
                if (isset($ignoredTraces[$data['method']]) && $k == $ignoredTraces[$data['method']]) {
                    unset($error->stacktrace->frames[$k]);
                }
            }

            $error->stacktrace->frames = array_values($error->stacktrace->frames);
        });

        $message = trim($message);
        $messageArray = explode("\n", $message);

        $errorClass = 'Application Error';
        $errorMessage = array_shift($messageArray);

        // handle exception messages
        if (preg_match('/exception \'(.*)\' with message \'(.*)\' in .*/', $errorMessage, $matches)) {
            $errorMessage = $matches[2];
            $errorClass = $matches[1];
        }

        if (count($messageArray) > 0) {
            $errorMessage .= '... [truncated]';
        }

        $this->client->notifyError(
            $errorClass,
            $errorMessage,
            array("notifier" => self::$NOTIFIER),
            $severity
        );

        return true;
    }

}
