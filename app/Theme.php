<?php

namespace App;

/**
 * Tema da interface escolhido por usuário no menu lateral. O claro é o padrão.
 */
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
}
