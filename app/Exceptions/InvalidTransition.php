<?php

namespace App\Exceptions;

use DomainException;

/** Perpindahan status yang tidak ada di PRD. Controller menerjemahkannya menjadi HTTP 409. */
class InvalidTransition extends DomainException {}
