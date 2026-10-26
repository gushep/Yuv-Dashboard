<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Screen;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Cadastro e edição de usuários; na edição a senha só muda quando informada.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->editedUser();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8), 'max:255'],
            'screens' => ['present', 'array'],
            'screens.*' => ['distinct', Rule::enum(Screen::class)],
            'can_update_items' => ['sometimes', 'boolean'],
            'can_create_tickets' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Impede que alguém tire de si mesmo o acesso ao cadastro e fique sem como desfazer.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('screens*') || ! $this->editedUser()?->is($this->user())) {
                    return;
                }
                if (! in_array(Screen::Users->value, $this->input('screens', []), true)) {
                    $validator->errors()->add('screens', 'Você não pode remover o seu próprio acesso à tela de Usuários.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Informe :attribute.',
            'email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe um usuário com este e-mail.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'max' => ':Attribute deve ter no máximo :max caracteres.',
            'screens.present' => 'Selecione as telas que o usuário pode acessar.',
            'screens.*.distinct' => 'Tela repetida na seleção.',
            'screens.*.enum' => 'Tela inválida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'o nome', 'email' => 'o e-mail', 'password' => 'a senha', 'screens' => 'as telas'];
    }

    private function editedUser(): ?User
    {
        return $this->route('user');
    }
}
