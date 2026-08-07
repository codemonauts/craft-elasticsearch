<?php

namespace codemonauts\elastic\services;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;
use codemonauts\elastic\Elastic;
use Craft;
use craft\base\Component;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Elasticsearch Client
 */
class Elasticsearch extends Component
{
    /**
     * @var string|null The authentication to use. You can use 'none', 'basicauth' or 'aws'.
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
            } elseif ($this->authentication === 'basicauth') {
                $this->client = ClientBuilder::create()
                    ->setHosts($this->hosts)
                    ->setBasicAuthentication($this->username, $this->password)
                    ->build();
            } elseif ($this->authentication === 'none') {
                $this->client = ClientBuilder::create()
                    ->setHosts($this->hosts)
                    ->build();
            } else {
                throw new InvalidConfigException('No valid authentication method set.');
            }
        }

        return $this->client;
    }

    /**
     * Returns whether the cluster supports the `match_bool_prefix` query.
     *
     * `match_bool_prefix` requires Elasticsearch 7.2+. A naive numeric version check is wrong for
     * OpenSearch: in compatibility mode it reports version 7.10.2, but with compatibility mode off
     * it reports its own 2.x/3.x, which fully supports the feature. We therefore branch on the
     * cluster's `version.distribution` first, then on the version number for Elasticsearch.
     *
     * The `matchBoolPrefix` setting forces the result either way. The detected value is cached
     * (keyed by plugin version) so this never queries the cluster per search. On any error we log
     * and assume no support, so callers fall back to `match_phrase_prefix`.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function supportsMatchBoolPrefix(): bool
    {
        $forced = Elastic::$settings->matchBoolPrefix;
        if ($forced !== null) {
            return $forced;
        }

        $cacheKey = 'elastic:capability:matchboolprefix:' . Elastic::$plugin->version;
        $cached = Craft::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return (bool)$cached;
        }

        $supported = false;
        try {
            $version = $this->getClient()->info()['version'] ?? [];
            $distribution = strtolower($version['distribution'] ?? 'elasticsearch');

            if ($distribution === 'opensearch') {
                // All OpenSearch releases support match_bool_prefix.
                $supported = true;
            } else {
                // Elasticsearch: available since 7.2.
                $supported = version_compare($version['number'] ?? '0', '7.2.0', '>=');
            }
        } catch (Throwable $e) {
            Craft::warning('Could not detect match_bool_prefix support, falling back to match_phrase_prefix: ' . $e->getMessage(), 'elastic');
        }

        Craft::$app->cache->set($cacheKey, $supported ? 1 : 0, 3600);

        return $supported;
    }
}
