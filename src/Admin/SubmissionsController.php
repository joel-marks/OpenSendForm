<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\Csrf;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\Submission\SubmissionRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin submissions screen: a paginated, filterable table (metadata only) plus
 * per-row and bulk retry actions.
 *
 * No submitted content is ever read or displayed here — only delivery
 * metadata. Retry actions drive the existing DeliveryService so the admin and
 * the cron share one code path.
 */
final class SubmissionsController
{
    /** Rows per page. */
    private const PER_PAGE = 50;

    /** Statuses offered in the filter (and the only valid filter values). */
    private const STATUSES = ['received', 'sent', 'failed', 'dead'];

    // --- List -------------------------------------------------------------

    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $query = $request->getQueryParams();

        $status = self::cleanStatus($query['status'] ?? null);
        $formId = self::cleanFormId($query['form'] ?? null);
        $page = max(1, (int) ($query['page'] ?? 1));

        $submissions = self::submissions($c);
        $total = $submissions->countFiltered($status, $formId);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $submissions->listPage($status, $formId, self::PER_PAGE, $offset);

        return AdminView::renderPage($c, $response, 'submissions', [
            'title'    => 'Submissions',
            'rows'     => $rows,
            'forms'    => self::forms($c)->listForms(),
            'statuses' => self::STATUSES,
            'status'   => $status ?? '',
            'formId'   => $formId,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
        ], 'submissions');
    }

    // --- Retry one --------------------------------------------------------

    public static function retry(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirectBack($response, $data);
        }

        if (!self::hasDelivery($c)) {
            self::flash($c)->error('Mail delivery is not configured, so retries are unavailable.');

            return self::redirectBack($response, $data);
        }

        $id = (int) ($args['id'] ?? 0);
        $submission = self::submissions($c)->findById($id);

        if ($submission === null) {
            self::flash($c)->error('That submission no longer exists.');

            return self::redirectBack($response, $data);
        }

        $status = (string) $submission['status'];
        if ($status !== 'failed' && $status !== 'dead') {
            self::flash($c)->error('Only failed or dead submissions can be retried.');

            return self::redirectBack($response, $data);
        }

        $result = self::delivery($c)->attemptDelivery($id);
        self::flash($c)->{$result === DeliveryService::RESULT_SENT ? 'success' : 'error'}(
            self::retryMessage($id, $result)
        );

        return self::redirectBack($response, $data);
    }

    // --- Retry all due ----------------------------------------------------

    public static function retryDue(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirectBack($response, $data);
        }

        if (!self::hasDelivery($c)) {
            self::flash($c)->error('Mail delivery is not configured, so retries are unavailable.');

            return self::redirectBack($response, $data);
        }

        $summary = self::delivery($c)->retryDue();

        if ($summary['attempted'] === 0) {
            self::flash($c)->info('No submissions were due for retry.');
        } else {
            self::flash($c)->success(sprintf(
                'Retried %d due submission(s): %d sent, %d still failed, %d dead.',
                $summary['attempted'],
                $summary[DeliveryService::RESULT_SENT],
                $summary[DeliveryService::RESULT_FAILED],
                $summary[DeliveryService::RESULT_DEAD]
            ));
        }

        return self::redirectBack($response, $data);
    }

    // --- Helpers ----------------------------------------------------------

    private static function retryMessage(int $id, string $result): string
    {
        return match ($result) {
            DeliveryService::RESULT_SENT   => "Submission #{$id} delivered.",
            DeliveryService::RESULT_FAILED => "Submission #{$id} still failing; another retry is scheduled.",
            DeliveryService::RESULT_DEAD   => "Submission #{$id} has exhausted its retries and is now dead.",
            default                        => "Submission #{$id} could not be retried.",
        };
    }

    private static function cleanStatus(mixed $status): ?string
    {
        $status = is_string($status) ? $status : '';

        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    private static function cleanFormId(mixed $form): ?int
    {
        if (is_string($form) && ctype_digit($form) && (int) $form > 0) {
            return (int) $form;
        }

        return null;
    }

    /**
     * Redirect back to the submissions list, preserving the filter/page the
     * action was invoked from (carried in hidden fields). Values are rebuilt
     * from scratch — never echoed from raw input — so there is no open-redirect
     * surface.
     *
     * @param array<string, mixed> $data
     */
    private static function redirectBack(ResponseInterface $response, array $data): ResponseInterface
    {
        $params = [];
        $status = self::cleanStatus($data['status'] ?? null);
        $formId = self::cleanFormId($data['form'] ?? null);
        $page = (int) ($data['page'] ?? 1);

        if ($status !== null) {
            $params['status'] = $status;
        }
        if ($formId !== null) {
            $params['form'] = (string) $formId;
        }
        if ($page > 1) {
            $params['page'] = (string) $page;
        }

        $location = '/admin/submissions';
        if ($params !== []) {
            $location .= '?' . http_build_query($params);
        }

        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private static function submissions(ContainerInterface $c): SubmissionRepository
    {
        /** @var SubmissionRepository $r */
        $r = $c->get(SubmissionRepository::class);

        return $r;
    }

    private static function forms(ContainerInterface $c): FormRepository
    {
        /** @var FormRepository $r */
        $r = $c->get(FormRepository::class);

        return $r;
    }

    private static function hasDelivery(ContainerInterface $c): bool
    {
        return $c->has(DeliveryService::class);
    }

    private static function delivery(ContainerInterface $c): DeliveryService
    {
        /** @var DeliveryService $d */
        $d = $c->get(DeliveryService::class);

        return $d;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

        return $s;
    }

    private static function flash(ContainerInterface $c): Flash
    {
        /** @var Flash $f */
        $f = $c->get(Flash::class);

        return $f;
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        parse_str((string) $request->getBody(), $data);

        return $data;
    }
}
