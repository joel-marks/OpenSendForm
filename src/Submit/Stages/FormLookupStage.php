<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (c): form lookup by _osf_key.
 *
 * A missing, unknown or inactive key is a single, deliberately
 * indistinguishable failure: unknown_form. (findByKey returns active
 * forms only.)
 */
final class FormLookupStage implements Stage
{
    private FormRepository $forms;

    public function __construct(FormRepository $forms)
    {
        $this->forms = $forms;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $key = $context->formKey;
        if ($key === null || $key === '') {
            return $this->unknown();
        }

        $form = $this->forms->findByKey($key);
        if ($form === null) {
            return $this->unknown();
        }

        $context->form = $form;

        return null;
    }

    private function unknown(): SubmitOutcome
    {
        return SubmitOutcome::error(403, 'unknown_form', 'Unknown or inactive form.');
    }
}
