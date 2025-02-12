<?php

namespace Modules\SSLManager\App\Jobs;

use App\Models\DomainSslCertificate;
use App\Settings;

class SecureDomain
{

    public $domainId;

    public function __construct($domainId)
    {
        $this->domainId = $domainId;
    }

    public function handle(): void
    {

        $findDomain = \App\Models\Domain::where('id', $this->domainId)->first();
        if (!$findDomain) {
            throw new \Exception('Domain not found');
        }
        $domainName = $findDomain->domain;

        $domainName = trim($domainName);
        $domainName = str_replace('www.', '', $domainName);
        if (empty($domainName)) {
            throw new \Exception('Domain name is empty');
        }
        $domainNameWww = 'www.' . $domainName;
        $domainNameWww = str_replace('www.www.', 'www.', $domainNameWww);


        $generalSettings = Settings::general();

        $sslCertificateFilePath = '/etc/ssl-manager/domains/' . $domainName . '/cert.pem';
        $sslCertificateKeyFilePath = '/etc/ssl-manager/domains/' . $domainName . '/privkey.pem';
        $sslCertificateChainFilePath = '/etc/ssl-manager/domains/' . $domainName . '/fullchain.pem';
        if (!is_dir('/etc/ssl-manager/domains/' . $domainName)) {
            shell_exec('mkdir -p /etc/ssl-manager/domains/' . $domainName);
        }

        shell_exec('chmod +x /usr/local/phyre/web/Modules/SSLManager/shell/acme.sh');
        $exec = shell_exec("bash /usr/local/phyre/web/Modules/SSLManager/shell/acme.sh  --register-account  -m " . $generalSettings['master_email'] . " --server zerossl");

        $tmpFile = '/tmp/acme-sh-zerossl-http-secure-command-' . $findDomain->id . '.sh';
        $certbotHttpSecureCommand = view('sslmanager::actions.acme-sh-http-secure-command', [
            'domain' => $domainName,
            'domainNameWww' => $domainNameWww,
            'domainRoot' => $findDomain->domain_root,
            'domainPublic' => $findDomain->domain_public,
            'email' => $generalSettings['master_email'],
            'country' => $generalSettings['master_country'],
            'locality' => $generalSettings['master_locality'],
            'organization' => $generalSettings['organization_name'],
        ])->render();

        file_put_contents($tmpFile, $certbotHttpSecureCommand);
        shell_exec('chmod +x ' . $tmpFile);

        $exec = shell_exec("bash $tmpFile");
        unlink($tmpFile);

        //check file
        $zerSslCert = '/root/.acme.sh/' . $domainName . '_ecc/' . $domainName . '.cer';
        $zerSslCertKey = '/root/.acme.sh/' . $domainName . '_ecc/' . $domainName . '.key';
        $zerSslCertIntermediate = '/root/.acme.sh/' . $domainName . '_ecc/ca.cer';
        $zerSslCertFullChain = '/root/.acme.sh/' . $domainName . '_ecc/fullchain.cer';

            if (!file_exists($zerSslCert)
                || !file_exists($zerSslCertKey)
                || !file_exists($zerSslCertFullChain)) {
                // Cant get all certificates
                throw new \Exception('Cant get certificates with ZeroSSL');
            }


        file_put_contents($sslCertificateFilePath, file_get_contents($zerSslCert));
        file_put_contents($sslCertificateKeyFilePath, file_get_contents($zerSslCertKey));
        file_put_contents($sslCertificateChainFilePath, file_get_contents($zerSslCertFullChain));


        if (!file_exists($sslCertificateFilePath)
            || !file_exists($sslCertificateKeyFilePath)
            || !file_exists($sslCertificateChainFilePath)) {
            // Cant get all certificates
            throw new \Exception('Cant get all certificates');
        }

        $sslCertificateFileContent = file_get_contents($sslCertificateFilePath);
        $sslCertificateKeyFileContent = file_get_contents($sslCertificateKeyFilePath);
        $sslCertificateChainFileContent = file_get_contents($sslCertificateChainFilePath);

        if (!empty($sslCertificateChainFileContent)) {
            $validateCertificates['certificate'] = $sslCertificateFileContent;
        }
        if (!empty($sslCertificateKeyFileContent)) {
            $validateCertificates['private_key'] = $sslCertificateKeyFileContent;
        }
        if (!empty($sslCertificateChainFileContent)) {
            $validateCertificates['certificate_chain'] = $sslCertificateChainFileContent;
        }
        if (count($validateCertificates) !== 3) {
            // Cant get all certificates
            throw new \Exception('Cant get all certificates');
        }


        $websiteSslCertificate = DomainSslCertificate::where('domain', $findDomain->domain)->first();

        if (!$websiteSslCertificate) {
            $websiteSslCertificate = new DomainSslCertificate();
            $websiteSslCertificate->domain = $findDomain->domain;
            $websiteSslCertificate->certificate = $validateCertificates['certificate'];
            $websiteSslCertificate->private_key = $validateCertificates['private_key'];
            $websiteSslCertificate->certificate_chain = $validateCertificates['certificate_chain'];
            $websiteSslCertificate->customer_id = $findDomain->customer_id;
            $websiteSslCertificate->is_active = 1;
            $websiteSslCertificate->is_wildcard = 0;
            $websiteSslCertificate->is_auto_renew = 1;
        }

        $websiteSslCertificate->provider = 'ACME';
        $websiteSslCertificate->save();

        $findDomain->configureVirtualHost(true);

    }
}
