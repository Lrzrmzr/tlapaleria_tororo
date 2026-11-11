<?php

namespace App\Services;

use RuntimeException;

/**
 * Se lanzó antes de llamar a Groq (o tras un 429) porque ya no queda cuota
 * gratuita en el periodo actual. El mensaje es apto para mostrarse al usuario.
 */
class GroqCuotaAgotada extends RuntimeException
{
}
