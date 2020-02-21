<?php
declare(strict_types=1);

namespace ArrayIterator\Service\Extension\OcrExtension\Controller;

use Apatis\Config\Config;
use Apatis\Config\ConfigInterface;
use ArrayIterator\Service\Core\Auth\Token;
use ArrayIterator\Service\Core\Entity\TokenIdentifierEntityInterface;
use ArrayIterator\Service\Core\Route\AbstractJsonController;
use ArrayIterator\Service\Module\GoogleModule\Lib\VisionAPI;
use GuzzleHttp\Exception\BadResponseException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpNotFoundException;
use Throwable;
use function json_decode;

// use for route
use ArrayIterator\Service\Core\Route\Annotation\Route;
use ArrayIterator\Service\Core\Route\Annotation\Api;

/**
 * Class OcrController
 * @package ArrayIterator\Service\Extension\OcrExtension\Controller
 *
 * @Route(@Api("/ocr"))
 */
class OcrController extends AbstractJsonController
{
    /**
     * @param ServerRequestInterface $request
     * @return TokenIdentifierEntityInterface|null
     */
    private function getAuthToken(ServerRequestInterface $request)
    {
        $token = $this->container->get(Token::class);
        return $token->getTokenEntityIdentifierFromRequest($request);
    }

    /**
     * Handle index OcrExtension Route
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $params
     * @return ResponseInterface
     *
     * @Route(
     *     "[/]",
     *     name="Api/Extension/OCR",
     *     methods="ANY"
     * )
     */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $params
    ) : ResponseInterface {
        if (!($token = $this->getAuthToken($request))) {
            return $this->unAuthorized();
        }

        // [
        //    'route_parameters' => $params,
        //    'server_parameters' => $request->getServerParams()
        //]
        return $this->badRequest();
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $params
     * @return ResponseInterface
     *
     * @Route(
     *     "/vision[/]",
     *     name="Api/Extension/OCR:Vision",
     *     methods="ANY"
     * )
     * @throws Throwable
     */
    public function vision(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $params
    ) : ResponseInterface {
        if (!($token = $this->getAuthToken($request))) {
            return $this->unAuthorized();
        }

        $options = $this->container->get(ConfigInterface::class);
        $config = $options->get('google', new Config());
        if (!$config instanceof ConfigInterface
            || !$config->get('api') instanceof ConfigInterface
        ) {
            throw new HttpNotFoundException(
                $request
            );
        }

        $config = $config->get('api')['vision'];
        if (!$config || !is_string($config)) {
            throw new HttpNotFoundException(
                $request
            );
        }
        $token = $this->container->get(Token::class);
        $tokenString = $token->getTokenStringFromRequest($request);
        // @todo add later
        // ----------------------------------------------------
        $opt = $options->toArray();
        $tokenList = $opt['auth']['token']??[];
        $tokenList = !is_array($tokenList) ? [] : $tokenList;
        if (!$tokenString || !isset($tokenList[$tokenString])) {
            return $this->unAuthorized();
        }

        $list = $tokenList[$tokenString];
        $user = $list;
        if (is_array($list)) {
            $user = $list['user']??'';
            if (isset($list['access'])) {
                $list['access'] = is_string($list['access'])
                    ? [$list['access']]
                    : $list['access'];
                if (!in_array('ocr', $list['access'])) {
                    return $this(401);
                }
            }
        }

        $user = is_string($user) ? $user : null;
        if ($user) {
            $this->setResponse($response->withHeader('X-Auth-User', $user));
        }

        // @todo add later END
        // ----------------------------------------------------
        //
        $vision = new VisionAPI($config);
        $image   = $request->getParsedBody()['image']??null;
        $limit   = $request->getParsedBody()['limit']??100;
        $context = $request->getParsedBody()['context']??null;
        if (!is_numeric($limit)) {
            $limit = 100;
        }

        $limit = (int)($limit);
        if ($context && (
                ! is_string($context)
                || !is_array(($context = json_decode($context, true)))
            )
        ) {
            return $this->preconditionFailed(
                'Precondition failed. Parameter `context` must be as json string.'
            );
        }

        /**
         * @var UploadedFileInterface $file
         */
        foreach ($request->getUploadedFiles() as $key => $file) {
            if ($key !== 'image') {
                $file->getStream()->close();
            }
        }
        if (!$image) {
            $image = $request->getUploadedFiles()['image']??null;
        }
        if (!($image)) {
            return $this->preconditionFailed(
                'Precondition failed. Parameter `image` required.'
            );
        }

        if (!is_string($image) && ! $image instanceof UploadedFileInterface) {
            return $this->preconditionFailed(
                'Precondition failed. Parameter `image` must be as a encode 64 string.'
            );
        }

        try {
            $vision = $vision->readFromBinary(
                $image,
                $limit,
                $context?:[]
            );
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                return $this->preconditionFailed(
                    sprintf('Precondition failed. %s', $e->getMessage())
                );
            }

            if ($e instanceof BadResponseException) {
                $message = json_decode((string) $e->getResponse()->getBody(), true);
                $message = is_array($message) ? ($message['error']['message']??null) : null;
                $e->getResponse()->getBody()->close();
                throw new HttpInternalServerErrorException(
                    $request,
                    sprintf(
                        '%s - %s',
                        $e->getResponse()->getStatusCode(),
                        $message ? : 'Bad Response From GoogleModule Vision.'
                    ),
                    $e
                );
            }
            throw $e;
        }

        if (!is_array($vision)) {
            return $this->expectationFailed(
                'Expectation Failed. No response given by google vision'
            );
        }

        return $this->success($vision);
    }
}
