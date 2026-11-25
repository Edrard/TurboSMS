<?php

namespace edrard\Turbosms\Exception;

/** The API returned the previous request result; inspect getResponse(), do not resend with a new ID. */
class DuplicateRequestException extends ApiException
{
}
