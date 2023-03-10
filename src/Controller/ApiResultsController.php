<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Result;
use App\Entity\User;
use App\Utility\Utils;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;


use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use function in_array;

/**
 * Class ApiResultsController
 *
 * @package App\Controller
 *
 * @Route(
 *     path=ApiResultsController::RUTA_API,
 *     name="api_results_"
 * )
 */
class ApiResultsController extends AbstractController
{
    public const RUTA_API = '/api/v1/results';

    private const HEADER_CACHE_CONTROL = 'Cache-Control';
    private const HEADER_ETAG = 'ETag';
    private const HEADER_ALLOW = 'Allow';
    const ROLE_ADMIN = 'ROLE_ADMIN';

    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $em)
    {
        $this->entityManager = $em;
    }

    /**
     * CGET Action
     * Summary: Retrieves the collection of Result resources.
     * Notes: Returns all results from the system that the user has access to.
     *
     * @param   Request $request
     * @return  Response
     * @Route(
     *     path=".{_format}/{sort?id}",
     *     defaults={"_format":"json","sort":"id"},
     *     requirements={
     *          "sort":"id|result|date",
     *          "_format":"json|xml"
     *     },
     *     methods={Request::METHOD_GET},
     *     name="cget"
     * )
     *
     * @Security(
     *     expression="is_granted('IS_AUTHENTICATED_FULLY')",
     *     statusCode=401,
     *     message="`Unauthorized`: Invalid credentials."
     * )
     */
    public function cgetAction(Request $request): Response
    {
        $order = $request->get('sort');

        $resultRespository = $this->entityManager
            ->getRepository(Result::class);

        if ($this->isGranted(self::ROLE_ADMIN)) {
            $results = $resultRespository
                ->findBy([], [$order => 'ASC']);
        }
        else {
            $email = $this->getUser()->getUserIdentifier();

            /** @var User $user */
            $user = $this->entityManager
                ->getRepository(User::class)
                ->findBy(['email' => $email]);

            $results = $resultRespository
                ->findBy(['user' => $user], [$order => 'ASC']);
        }

        $format = Utils::getFormat($request);

        // No hay resultados?
        // @codeCoverageIgnoreStart
        if (empty($results)) {
            return $this->errorMessage(Response::HTTP_NOT_FOUND, null, $format);    // 404
        }
        // @codeCoverageIgnoreEnd

        // Caching with ETag
        $etag = md5((string) json_encode($results));
        if ($etags = $request->getETags()) {
            if (in_array($etag, $etags) || in_array('*', $etags)) {
                return new Response(null, Response::HTTP_NOT_MODIFIED); // 304
            }
        }

        return Utils::apiResponse(
            Response::HTTP_OK,
            [ 'results' => array_map(fn ($r) =>  ['result' => $r], $results) ],
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'must-revalidate',
                self::HEADER_ETAG => $etag,
            ]
        );
    }

    /**
     * GET Action
     * Summary: Retrieves a Result resource based on a single ID.
     * Notes: Returns the result identified by &#x60;resultId&#x60;.
     *
     * @param Request $request
     * @param  int $resultId Result id
     * @return Response
     * @Route(
     *     path="/{resultId}.{_format}",
     *     defaults={ "_format": null },
     *     requirements={
     *          "resultId": "\d+",
     *          "_format": "json|xml"
     *     },
     *     methods={ Request::METHOD_GET },
     *     name="get"
     * )
     *
     * @Security(
     *     expression="is_granted('IS_AUTHENTICATED_FULLY')",
     *     statusCode=401,
     *     message="`Unauthorized`: Invalid credentials."
     * )
     */
    public function getAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);

        /** @var Result $result */
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->find($resultId);

        $email = $this->getUser()->getUserIdentifier();

        if($result == null || ($result->getUserIdentifier() != $email && !$this->isGranted(self::ROLE_ADMIN))) {
            return $this->errorMessage(Response::HTTP_NOT_FOUND, null, $format);    // 404
        }

        // Caching with ETag
        $etag = md5((string) json_encode($result));
        if ($etags = $request->getETags()) {
            if (in_array($etag, $etags) || in_array('*', $etags)) {
                return new Response(null, Response::HTTP_NOT_MODIFIED); // 304
            }
        }

        return Utils::apiResponse(
            Response::HTTP_OK,
            [ Result::RESULT_ATTR => $result ],
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'must-revalidate',
                self::HEADER_ETAG => $etag,
            ]
        );
    }

    /**
     * Summary: Provides the list of HTTP supported methods
     * Notes: Return a &#x60;Allow&#x60; header with a list of HTTP supported methods.
     *
     * @param  int $resultId Result id
     * @return Response
     * @Route(
     *     path="/{resultId}.{_format}",
     *     defaults={ "resultId" = 0, "_format": "json" },
     *     requirements={
     *          "resultId": "\d+",
     *         "_format": "json|xml"
     *     },
     *     methods={ Request::METHOD_OPTIONS },
     *     name="options"
     * )
     */
    public function optionsAction(int $resultId): Response
    {
        $methods = $resultId
            ? [ Request::METHOD_GET, Request::METHOD_PUT, Request::METHOD_DELETE ]
            : [ Request::METHOD_GET, Request::METHOD_POST ];
        $methods[] = Request::METHOD_OPTIONS;

        return new Response(
            null,
            Response::HTTP_NO_CONTENT,
            [
                self::HEADER_ALLOW => implode(', ', $methods),
                self::HEADER_CACHE_CONTROL => 'public, inmutable'
            ]
        );
    }

    /**
     * DELETE Action
     * Summary: Removes the Result resource.
     * Notes: Deletes the result identified by &#x60;resultId&#x60;.
     *
     * @param   Request $request
     * @param   int $resultId Result id
     * @return  Response
     * @Route(
     *     path="/{resultId}.{_format}",
     *     defaults={ "_format": null },
     *     requirements={
     *          "resultId": "\d+",
     *         "_format": "json|xml"
     *     },
     *     methods={ Request::METHOD_DELETE },
     *     name="delete"
     * )
     *
     * @Security(
     *     expression="is_granted('IS_AUTHENTICATED_FULLY')",
     *     statusCode=401,
     *     message="`Unauthorized`: Invalid credentials."
     * )
     */
    public function deleteAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);

        /** @var Result $result */
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->find($resultId);

        $email = $this->getUser()->getUserIdentifier();

        if($result == null || ($result->getUserIdentifier() != $email && !$this->isGranted(self::ROLE_ADMIN))) {
            return $this->errorMessage(Response::HTTP_NOT_FOUND, null, $format);    // 404
        }

        $this->entityManager->remove($result);
        $this->entityManager->flush();

        return Utils::apiResponse(Response::HTTP_NO_CONTENT);
    }

    /**
     * POST action
     * Summary: Creates a User resource.
     *
     * @param Request $request request
     * @return Response
     * @Route(
     *     path=".{_format}",
     *     defaults={ "_format": null },
     *     requirements={
     *         "_format": "json|xml"
     *     },
     *     methods={ Request::METHOD_POST },
     *     name="post"
     * )
     *
     * @Security(
     *     expression="is_granted('IS_AUTHENTICATED_FULLY')",
     *     statusCode=401,
     *     message="`Unauthorized`: Invalid credentials."
     * )
     * @throws Exception
     */
    public function postAction(Request $request): Response
    {
        $format = Utils::getFormat($request);
        $body = $request->getContent();
        $postData = json_decode((string) $body, true);

        if (!isset($postData[Result::RESULT_ATTR], $postData[Result::DATE_ATTR])) {
            // 422 - Unprocessable Entity -> Faltan datos
            return $this->errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
        }

        $email = $this->getUser()->getUserIdentifier();

        /** @var User $user */
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        $result = new Result(
            $postData[Result::RESULT_ATTR],
            $user,
            new DateTime($postData[Result::DATE_ATTR])
        );

        $this->entityManager->persist($result);
        $this->entityManager->flush();

        return Utils::apiResponse(
            Response::HTTP_CREATED,
            [ Result::RESULT_ATTR => $result ],
            $format,
            [
                'Location' => $request->getScheme() . '://' . $request->getHttpHost() .
                    self::RUTA_API . '/' . $result->getId(),
            ]
        );
    }

    /**
     * PUT action
     * Summary: Updates the Result resource.
     * Notes: Updates the result identified by &#x60;resultId&#x60;.
     *
     * @param Request $request request
     * @param int $resultId Result id
     * @return  Response
     * @Route(
     *     path="/{resultId}.{_format}",
     *     defaults={ "_format": null },
     *     requirements={
     *          "resultId": "\d+",
     *         "_format": "json|xml"
     *     },
     *     methods={ Request::METHOD_PUT },
     *     name="put"
     * )
     *
     * @Security(
     *     expression="is_granted('IS_AUTHENTICATED_FULLY')",
     *     statusCode=401,
     *     message="`Unauthorized`: Invalid credentials."
     * )
     * @throws Exception
     */
    public function putAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);

        /** @var Result $result */
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->find($resultId);

        // Optimistic Locking (strong validation)

        $etag = md5((string) json_encode($result));
        if ($request->headers->has('If-Match') && $etag != $request->headers->get('If-Match')) {
            return $this->errorMessage(
                Response::HTTP_PRECONDITION_FAILED,
                'PRECONDITION FAILED: one or more conditions given evaluated to false: (' . $etag .')',
                $format
            ); // 412
        }


        $email = $this->getUser()->getUserIdentifier();

        if($result == null || ($result->getUserIdentifier() != $email && !$this->isGranted(self::ROLE_ADMIN))) {
            return $this->errorMessage(Response::HTTP_NOT_FOUND, null, $format);    // 404
        }

        $body = (string) $request->getContent();
        $postData = json_decode($body, true);

        $numberColumnsToUpdate = 0;
        if (isset($postData[Result::RESULT_ATTR])) {
            $numberColumnsToUpdate++;
            $result->setResult($postData[Result::RESULT_ATTR]);
        }

        if(isset($postData[Result::DATE_ATTR])) {
            $numberColumnsToUpdate++;
            $result->setDate(new DateTime($postData[Result::DATE_ATTR]));
        }

        if($numberColumnsToUpdate > 0) {
            $this->entityManager->flush();
            return Utils::apiResponse(
                209,                        // 209 - Content Returned
                [ Result::RESULT_ATTR => $result ],
                $format
            );
        }
        // 422 - Unprocessable Entity -> Faltan datos
        return $this->errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
    }

    /**
     * Error Message Response
     * @param int $status
     * @param string|null $customMessage
     * @param string $format
     *
     * @return Response
     */
    private function errorMessage(int $status, ?string $customMessage, string $format): Response
    {
        $customMessage = new Message(
            $status,
            $customMessage ?? strtoupper(Response::$statusTexts[$status])
        );
        return Utils::apiResponse(
            $customMessage->getCode(),
            $customMessage,
            $format
        );
    }
}