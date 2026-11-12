<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Voto de uma pessoa numa carta revelada (cada pessoa tem um número limitado de votos por retro). */
#[Fillable(['retrospective_card_id', 'user_id'])]
class RetrospectiveVote extends Model {}
