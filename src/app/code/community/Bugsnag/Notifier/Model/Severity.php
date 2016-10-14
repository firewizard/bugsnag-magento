<?php

class Bugsnag_Notifier_Model_Severity
{
    public function toOptionArray()
    {
        return array(
            array('value' => 'error', 'label' => Mage::helper('adminhtml')->__('Crashes & errors')),
            array('value' => 'error,warning', 'label' => Mage::helper('adminhtml')->__('Crashes, errors & warnings')),
            array('value' => 'error,warning,info', 'label' => Mage::helper('adminhtml')->__('Crashes, errors, warnings & info messages'))
        );
    }
}