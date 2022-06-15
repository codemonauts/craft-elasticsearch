<?php

namespace codemonauts\elastic\services;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;
use craft\base\Component;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use yii\base\InvalidConfigException;

/**
 * Elasticsearch Client
 */
class Elasticsearch extends Component
{
    /**
     * @var string|null The authentication to use. You can use 'realm' or 'aws'.
     */
    public ?string $authentication;

    /**
     * @var string[]|null The Elasticsearch hosts to connect to.
     */
    public ?array $hosts;

    /**
     * @var string|null The username to use for authentication. Leave blank if you use AWS Elasticsearch/OpenSearch and instance profile to authenticate.
     */
    public ?string $username;

    /**
     * @var string|null The password to use for authentication.
     */
    public ?string $password;

    /**
     * @var string|null The AWS domain region.
     */
    public ?string $region;

    /**
     * @var Client|null The elasticsearch client.
     */
    private ?Client $client = null;

    /**
     * Returns the Elasticsearch client.
     *
     * @return Client
     * @throws InvalidConfigException
     */
    public function getClient(): Client
    {
        if ($this->client === null) {
            if ($this->authentication === 'aws') {
                if ($this->username != '') {
                    $provider = CredentialProvider::fromCredentials(
                        new Credentials($this->username, $this->password)
                    );
                } else {
                    $provider = CredentialProvider::instanceProfile();
                }

                $handler = new ElasticsearchPhpHandler($this->region, $provider);

                $this->client = ClientBuilder::create()
                    ->setHandler($handler)
                    ->setHosts($this->hosts)
                    ->build();
            } else if ($this->authentication === 'basicauth') {
                $this->client = ClientBuilder::create()
                    ->setHosts($this->hosts)
                    ->setBasicAuthentication($this->username, $this->password)
                    ->build();
            } else {
                throw new InvalidConfigException('No valid authentication method set.');
            }
        }

        return $this->client;
    }
}