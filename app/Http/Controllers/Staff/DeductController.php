<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\DeductPointsRequest;
use App\Services\Card\CardService;
use App\Services\Card\TransactionService;
use App\Services\I18nService;
use App\Services\Member\MemberService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class DeductController extends Controller
{
    /**
     * Form to remove points from a member's balance on a card.
     */
    public function showDeductPoints(
        string $locale,
        string $member_identifier,
        string $card_identifier,
        MemberService $memberService,
        CardService $cardService,
        I18nService $i18nService
    ): View {
        $member = $memberService->findActiveByIdentifier($member_identifier);
        $card = $cardService->findActiveCardByIdentifier($card_identifier);
        if (!$card) {
            abort(404);
        }

        if (!auth('staff')->user()->isRelatedToCard($card)) {
            abort(401);
        }

        $currency = $i18nService->getCurrencyDetails($card->currency);
        $balance = $card->getMemberBalance($member);

        return view('staff.deduct.points', compact('card', 'member', 'currency', 'balance'));
    }

    /**
     * Process staff point deduction (FIFO against credit transactions).
     */
    public function postDeductPoints(
        string $locale,
        string $member_identifier,
        string $card_identifier,
        DeductPointsRequest $request,
        TransactionService $transactionService
    ): RedirectResponse {
        $staffUser = auth('staff')->user();

        $result = $transactionService->deductPoints(
            $member_identifier,
            $card_identifier,
            $staffUser,
            (int) $request->points,
            $request->image,
            $request->note
        );

        if ($result === false) {
            session()->flash('error', trans('common.insufficient_points_to_remove'));

            return redirect()->route('staff.deduct.points', [
                'member_identifier' => $member_identifier,
                'card_identifier' => $card_identifier,
            ]);
        }

        session()->flash('success', trans('common.points_removed_success'));

        return redirect()->route('staff.transactions', [
            'member_identifier' => $member_identifier,
            'card_identifier' => $card_identifier,
        ]);
    }
}
