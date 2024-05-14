<?php

namespace App;

use App\Models\DomainSslCertificate;

class MasterDomain
{
    public $domain;

    public $email;

    public $country = 'US';

    public $locality = 'New York';

    public $organization = 'PhyrePanel';

    public $domainPublic = '/var/www/html';

    public $domainRoot = '/var/www';

    public function __construct()
    {
        $generalSettings = Settings::general();

        $this->domain = $generalSettings['master_domain'];
        $this->email = $generalSettings['master_email'];
        $this->country = $generalSettings['master_country'];
        $this->locality = $generalSettings['master_locality'];
        $this->organization = $generalSettings['organization_name'];

    }

    public function configureVirtualHost($fixPermissions = false)
    {
        // check is valid domain
        if (!filter_var($this->domain, FILTER_VALIDATE_DOMAIN)) {
            return false;
        }

        $apacheVirtualHostBuilder = new \App\VirtualHosts\ApacheVirtualHostBuilder();
        $apacheVirtualHostBuilder->setDomain($this->domain);
        $apacheVirtualHostBuilder->setDomainPublic($this->domainPublic);
        $apacheVirtualHostBuilder->setDomainRoot($this->domainRoot);
        $apacheVirtualHostBuilder->setHomeRoot($this->domainRoot);
        $apacheVirtualHostBuilder->setServerAdmin($this->email);
        $apacheVirtualHostBuilder->setDomainAlias('*.'.$this->domain);

        $apacheBaseConfig = $apacheVirtualHostBuilder->buildConfig();

        if ($fixPermissions) {
            shell_exec('mkdir -p /var/www/logs/apache2');
            shell_exec('touch /var/www/logs/apache2/bytes.log');
            shell_exec('touch /var/www/logs/apache2/access.log');
            shell_exec('touch /var/www/logs/apache2/error.log');
        }

        // Install SSL
        $findDomainSSLCertificate = null;

        $catchMainDomain = '';
        $domainExp = explode('.', $this->domain);
        if (count($domainExp) > 0) {
            unset($domainExp[0]);
            $catchMainDomain = implode('.', $domainExp);
        }

        // Try to find wildcard SSL certificate
        $findDomainSSLCertificateWildcard = \App\Models\DomainSslCertificate::where('domain', '*.' . $this->domain)
            ->where('is_wildcard', 1)
            ->first();
        if ($findDomainSSLCertificateWildcard) {
            $findDomainSSLCertificate = $findDomainSSLCertificateWildcard;
        } else {
            $findDomainSSL = \App\Models\DomainSslCertificate::where('domain', $this->domain)->first();
            if ($findDomainSSL) {
                $findDomainSSLCertificate = $findDomainSSL;
            } else {
                $findMainDomainWildcardSSLCertificate = \App\Models\DomainSslCertificate::where('domain', '*.'.$catchMainDomain)
                    ->first();
                if ($findMainDomainWildcardSSLCertificate) {
                    $findDomainSSLCertificate = $findMainDomainWildcardSSLCertificate;
                }
            }
        }

        $apacheBaseConfigWithSSL = null;

        if ($findDomainSSLCertificate) {

            $certsFolderName = $findDomainSSLCertificate->domain;
            $certsFolderName = str_replace('*.', 'wildcard.', $certsFolderName);

            $sslCertificateFile = $this->domainRoot . '/certs/' . $certsFolderName . '/public/cert.pem';
            $sslCertificateKeyFile = $this->domainRoot . '/certs/' . $certsFolderName . '/private/key.private.pem';
            $sslCertificateChainFile = $this->domainRoot . '/certs/' . $certsFolderName . '/public/fullchain.pem';

            if (!empty($findDomainSSLCertificate->certificate)) {
                if (!is_dir($this->domainRoot . '/certs/' . $certsFolderName . '/public')) {
                    mkdir($this->domainRoot . '/certs/' . $certsFolderName . '/public', 0755, true);
                }
                file_put_contents($sslCertificateFile, $findDomainSSLCertificate->certificate);
            }
            if (!empty($findDomainSSLCertificate->private_key)) {
                if (!is_dir($this->domainRoot . '/certs/' . $certsFolderName . '/private')) {
                    mkdir($this->domainRoot . '/certs/' . $certsFolderName . '/private', 0755, true);
                }
                file_put_contents($sslCertificateKeyFile, $findDomainSSLCertificate->private_key);
            }
            if (!empty($findDomainSSLCertificate->certificate_chain)) {
                if (!is_dir($this->domainRoot . '/certs/' . $certsFolderName . '/public')) {
                    mkdir($this->domainRoot . '/certs/' . $certsFolderName . '/public', 0755, true);
                }
                file_put_contents($sslCertificateChainFile, $findDomainSSLCertificate->certificate_chain);
            }

            $apacheVirtualHostBuilder->setPort(443);
            $apacheVirtualHostBuilder->setSSLCertificateFile($sslCertificateFile);
            $apacheVirtualHostBuilder->setSSLCertificateKeyFile($sslCertificateKeyFile);
            $apacheVirtualHostBuilder->setSSLCertificateChainFile($sslCertificateChainFile);

            $apacheBaseConfigWithSSL = $apacheVirtualHostBuilder->buildConfig();


        }
        // End install SSL

        if ($fixPermissions) {
        $domainIndexFile = $this->domainPublic . '/index.html';
        if (file_exists($domainIndexFile)) {
            $domainIndexFileContent = file_get_contents($domainIndexFile);
            if (str_contains($domainIndexFileContent, 'Apache2 Debian Default Page')) {
                 $indexContent = file_get_contents(base_path('resources/views/actions/samples/apache/html/app-index.html'));
                 file_put_contents($this->domainPublic . '/index.html', $indexContent);
            }
        }

        shell_exec('chown -R www-data:www-data ' . $this->domainPublic);
        shell_exec('chmod -R 755 ' . $this->domainPublic);
        }

        return [
            'apacheBaseConfig' => $apacheBaseConfig,
            'apacheBaseConfigWithSSL' => $apacheBaseConfigWithSSL ?? null
        ];

    }
}
