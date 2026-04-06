<?php

namespace App\Http\Requests\Staff;

use App\Services\Card\CardService;
use App\Services\Member\MemberService;
use Illuminate\Foundation\Http\FormRequest;

class DeductPointsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $member = app(MemberService::class)->findActiveByIdentifier($this->route('member_identifier'));
        $card = app(CardService::class)->findActiveCardByIdentifier($this->route('card_identifier'));
        $maxBalance = ($card && $member) ? $card->getMemberBalance($member) : 0;

        return [
            'points' => ['required', 'integer', 'min:1', 'max:'.$maxBalance],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
            'note' => 'nullable|max:1024',
        ];
    }
}
