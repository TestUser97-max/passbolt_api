<?php
/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SARL (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SARL (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         2.0.0
 */
namespace Passbolt\WebInstaller\Controller;

use App\Utility\Gpg;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Passbolt\WebInstaller\Form\GpgKeyImportForm;

class GpgKeyImportController extends WebInstallerController
{
    // Components.
    var $components = ['Flash'];

    // Gpg lib.
    var $Gpg = null;

    // Gpg key import form.
    var $GpgKeyImportForm = null;

    /**
     * Initialize.
     */
    public function initialize()
    {
        parent::initialize();
        $this->stepInfo['previous'] = 'install/database';
        $this->stepInfo['next'] = 'install/email';
        $this->stepInfo['template'] = 'Pages/gpg_key_import';
        $this->stepInfo['generate_key_cta'] = 'install/gpg_key';

        $this->Gpg = new Gpg();
        $this->GpgKeyImportForm = new GpgKeyImportForm();
    }

    /**
     * Index
     */
    function index() {
        if(!empty($this->request->getData())) {
            $data = $this->request->getData();
            $this->_validateData($data);
            $data['fingerprint'] = $this->_importKeyIntoKeyring($data['armored_key']);
            $this->_checkEncryptDecrypt($data['armored_key']);
            $this->_exportArmoredKeysIntoConfig($data['fingerprint']);
            $this->_saveConfiguration($data);

            return $this->_success();
        }

        $this->render($this->stepInfo['template']);
    }

    /**
     * Import key into keyring.
     * @param $armoredKey
     * @return string fingerprint.
     */
    protected function _importKeyIntoKeyring($armoredKey) {
        try {
            $fingerprint = $this->Gpg->importKeyIntoKeyring($armoredKey);
        } catch (Exception $e) {
            return $this->_error($e->getMessage());
        }
        return $fingerprint;
    }

    /**
     * Export armored keys into config.
     * @param $fingerprint
     * @return bool|void
     */
    protected function _exportArmoredKeysIntoConfig($fingerprint) {
        try {
            $this->GpgKeyImportForm->exportArmoredKeys($fingerprint);
        } catch (Exception $e) {
            return $this->_error($e->getMessage());
        }
        return true;
    }

    /**
     * Save configuration.
     * @param $data
     */
    protected function _saveConfiguration($data) {
        $session = $this->request->getSession();
        $session->write(self::CONFIG_KEY . '.gpg', [
            'fingerprint' => $data['fingerprint'],
            'public' => Configure::read('passbolt.gpg.serverKey.public'),
            'private' => Configure::read('passbolt.gpg.serverKey.private')
        ]);
    }

    /**
     * Validate data.
     * @param $data
     * @return string key fingerprint
     */
    protected function _validateData($data) {
        $gpgKeyImportForm = new GpgKeyImportForm();
        $confIsValid = $gpgKeyImportForm->execute($data);
        $this->set('gpgKeyImportForm', $gpgKeyImportForm);

        if (!$confIsValid) {
            return $this->_error(__('This is not a valid GPG key'));
        }

        $keyInfo = $this->_getAndAssertGpgkey($data['armored_key']);
        if ($keyInfo === false) {
            return $this->_error(__('This is not a valid GPG key'));
        }

        if ($keyInfo['expires'] !== null) {
            return $this->_error(__('GPG keys with expiry date are currently not supported. Please use another key without expiry date.'));
        }

        return $keyInfo['fingerprint'];
    }

    /**
     * Check that the key provided can be used to encrypt and decrypt.
     * @param $armoredKey
     */
    protected function _checkEncryptDecrypt($armoredKey) {
        try {
            $messageToEncrypt = 'open source password manager for teams';
            $this->Gpg->setEncryptKey($armoredKey);
            $this->Gpg->setSignKey($armoredKey);
            $encryptedMessage = $this->Gpg->encrypt($messageToEncrypt, true);
            $this->Gpg->setDecryptKey($armoredKey);
            $decryptedMessage = $this->Gpg->decrypt($encryptedMessage, '', true);
        } catch (Exception $e) {
            return $this->_error(__('This key cannot be used by passbolt. Please note that passbolt does not support GPG key with master passphrase. Error message: {0}', [$e->getMessage()]));
        } catch(\Exception $e) {
            return $this->_error(__('This key cannot be used by passbolt. Please note that passbolt does not support GPG key with master passphrase. Error message: {0}', [$e->getMessage()]));
        }

        if ($messageToEncrypt !== $decryptedMessage) {
            return $this->_error(__('Encrypt / decrypt operation returned an incorrect result. The key does not seem to be valid.'));
        }
    }

    /**
     * Parses a gpg key and verifies that it's readable and with a valid format.
     *
     * @param string $armoredKey the armored key
     * @return array|boolean information array
     */
    protected function _getAndAssertGpgkey($armoredKey)
    {
        $gpg = new Gpg();
        if (!$gpg->isParsableArmoredPrivateKeyRule($armoredKey)) {
            return false;
        }
        try {
            $gpg = new Gpg();
            $info = $gpg->getKeyInfo($armoredKey);
        } catch (Exception $e) {
           return false;
        }

        return $info;
    }
}