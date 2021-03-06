<?php

declare(strict_types=1);

namespace CedricZiel\HetznerCloudAPI\Api;

use CedricZiel\HetznerCloudAPI\Client;
use CedricZiel\HetznerCloudAPI\HttpClient\Message\QueryStringBuilder;
use CedricZiel\HetznerCloudAPI\HttpClient\Message\ResponseMediator;
use Http\Discovery\StreamFactoryDiscovery;
use Http\Message\MultipartStream\MultipartStreamBuilder;
use Http\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Abstract class for Api classes.
 *
 * @author Joseph Bielawski <stloyd@gmail.com>
 * @author Matt Humphrey <matt@m4tt.co>
 * @author Radu Topala <radu.topala@trisoft.ro>
 */
abstract class AbstractApi implements ApiInterface
{
    /**
     * The client.
     *
     * @var Client
     */
    protected $client;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @param Client             $client
     * @param StreamFactory|null $streamFactory
     */
    public function __construct(Client $client, StreamFactory $streamFactory = null)
    {
        $this->client = $client;
        $this->streamFactory = $streamFactory ?: StreamFactoryDiscovery::find();
    }

    /**
     * @return $this
     * @codeCoverageIgnore
     */
    public function configure()
    {
        return $this;
    }

    /**
     * Performs a GET query and returns the response as a PSR-7 response object.
     *
     * @param string $path
     * @param array  $parameters
     * @param array  $requestHeaders
     *
     * @return ResponseInterface
     */
    protected function getAsResponse($path, array $parameters = [], $requestHeaders = [])
    {
        $path = $this->preparePath($path, $parameters);

        return $this->client->getHttpClient()->get($path, $requestHeaders);
    }

    /**
     * @param string $path
     * @param array  $parameters
     * @param array  $requestHeaders
     *
     * @return mixed
     */
    protected function get($path, array $parameters = [], $requestHeaders = [])
    {
        return ResponseMediator::getContent($this->getAsResponse($path, $parameters, $requestHeaders));
    }

    /**
     * @param string $path
     * @param array  $parameters
     * @param array  $requestHeaders
     * @param array  $files
     *
     * @return mixed
     */
    protected function post($path, array $parameters = [], $requestHeaders = [], array $files = [])
    {
        $path = $this->preparePath($path);

        $body = null;
        if (empty($files) && !empty($parameters)) {
            $body = $this->streamFactory->createStream(json_encode($parameters));
            $requestHeaders['Content-Type'] = 'application/json';
        } elseif (!empty($files)) {
            $builder = new MultipartStreamBuilder($this->streamFactory);

            foreach ($parameters as $name => $value) {
                $builder->addResource($name, $value);
            }

            foreach ($files as $name => $file) {
                $builder->addResource($name, fopen($file, 'r'), [
                    'headers' => [
                        'Content-Type' => $this->guessContentType($file),
                    ],
                    'filename' => basename($file),
                ]);
            }

            $body = $builder->build();
            $requestHeaders['Content-Type'] = 'multipart/form-data; boundary='.$builder->getBoundary();
        }

        $response = $this->client->getHttpClient()->post($path, $requestHeaders, $body);

        return ResponseMediator::getContent($response);
    }

    /**
     * @param string $path
     * @param array  $parameters
     * @param array  $requestHeaders
     *
     * @return mixed
     */
    protected function put($path, array $parameters = [], $requestHeaders = [])
    {
        $path = $this->preparePath($path);

        $body = null;
        if (!empty($parameters)) {
            $body = $this->streamFactory->createStream(json_encode($parameters));
            $requestHeaders['Content-Type'] = 'application/json';
        }

        $response = $this->client->getHttpClient()->put($path, $requestHeaders, $body);

        return ResponseMediator::getContent($response);
    }

    /**
     * @param string $path
     * @param array  $parameters
     * @param array  $requestHeaders
     *
     * @return mixed
     */
    protected function delete($path, array $parameters = [], $requestHeaders = [])
    {
        $path = $this->preparePath($path, $parameters);

        $response = $this->client->getHttpClient()->delete($path, $requestHeaders);

        return ResponseMediator::getContent($response);
    }

    /**
     * @param int    $id
     * @param string $path
     *
     * @return string
     */
    protected function getServerPath($id, $path)
    {
        return 'servers/'.$this->encodePath($id).'/'.$path;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function encodePath($path)
    {
        $path = rawurlencode((string) $path);

        return str_replace('.', '%2E', $path);
    }

    /**
     * Create a new OptionsResolver with page and per_page options.
     *
     * @return OptionsResolver
     */
    protected function createOptionsResolver()
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined('page')
            ->setAllowedTypes('page', 'int')
            ->setAllowedValues('page', function ($value) {
                return $value > 0;
            })
        ;
        $resolver->setDefined('per_page')
            ->setAllowedTypes('per_page', 'int')
            ->setAllowedValues('per_page', function ($value) {
                return $value > 0 && $value <= 100;
            })
        ;

        return $resolver;
    }

    private function preparePath($path, array $parameters = [])
    {
        if (count($parameters) > 0) {
            $path .= '?'.QueryStringBuilder::build($parameters);
        }

        return $path;
    }

    /**
     * @param $file
     *
     * @return string
     */
    private function guessContentType($file)
    {
        if (!class_exists(\finfo::class, false)) {
            return 'application/octet-stream';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($file);
    }
}
