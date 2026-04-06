<?php

namespace App\Services\Card;

use Illuminate\Database\Eloquent\Collection;
use App\Services\Member\MemberService;
use App\Services\Card\RewardService;
use App\Models\Staff;
use App\Models\Transaction;
use App\Models\Member;
use App\Models\Card;
use Money\Currency;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;
use Carbon\Carbon;
use App\Notifications\Member\RewardClaimed;
use App\Notifications\Member\PointsReceived;
use Illuminate\Support\Facades\DB;

/**
 * Class TransactionService
 * 
 * Handles operations related to transactions.
 */
class TransactionService
{
    /**
     * @var MemberService
     */
    protected $memberService;

    /**
     * @var CardService
     */
    protected $cardService;

    /**
     * @var RewardService
     */
    protected $rewardService;

    /**
     * @var AnalyticsService
     */
    protected $analyticsService;

    /**
     * TransactionService constructor.
     *
     * @param MemberService $memberService
     * @param CardService $cardService
     * @param RewardService $rewardService
     * @param AnalyticsService $analyticsService
     */
    public function __construct(MemberService $memberService, CardService $cardService, RewardService $rewardService, AnalyticsService $analyticsService)
    {
        $this->memberService = $memberService;
        $this->cardService = $cardService;
        $this->rewardService = $rewardService;
        $this->analyticsService = $analyticsService;
    }

    /**
     * Retrieves transactions of a given member for a specific card.
     *
     * This function fetches all transactions by default. However, 
     * if $showExpiredAndUsedTransactions is set to false, the returned collection will
     * exclude credit transactions ('initial_bonus_points', 'staff_credited_points_for_purchase',
     * 'staff_credited_points') where points have expired or been fully used, but will always
     * include redemptions and staff debits ('staff_redeemed_points_for_reward', 'staff_debited_points').
     *
     * @param Member $member The member associated with the transactions.
     * @param Card $card The card associated with the transactions.
     * @param bool $showExpiredAndUsedTransactions Determines whether to include transactions 
     * where points have expired or been fully used. Default is true.
     *
     * @return Collection The collection of relevant Transaction instances.
     */
    public function findTransactionsOfMemberForCard(
        Member $member,
        Card $card,
        bool $showExpiredAndUsedTransactions = true
    ): Collection {
        // Define the query to retrieve all transactions for the given member and card.
        $query = Transaction::where('member_id', $member->id)
                            ->where('card_id', $card->id)
                            ->orderBy('created_at', 'desc');

        // If expired and fully used credit buckets should be hidden, keep audit-style rows visible.
        if ($showExpiredAndUsedTransactions === false) {
            $creditEvents = ['initial_bonus_points', 'staff_credited_points_for_purchase', 'staff_credited_points'];
            $alwaysShowEvents = ['staff_redeemed_points_for_reward', 'staff_debited_points'];

            $query->where(function ($q) use ($creditEvents, $alwaysShowEvents) {
                $q->whereIn('event', $alwaysShowEvents)
                    ->orWhere(function ($q2) use ($creditEvents) {
                        $q2->whereIn('event', $creditEvents)
                            ->where('expires_at', '>=', Carbon::now())
                            ->whereColumn('points', '>', 'points_used');
                    });
            });
        }

        // Execute the query and return the collection of transactions.
        return $query->get();
    }

    /**
     * Consume points from a member's non-expired credit transactions (FIFO by created_at).
     */
    protected function consumeMemberCardPointsFifo(Member $member, Card $card, int $amount): void
    {
        $transactions = Transaction::where('member_id', $member->id)
            ->where('card_id', $card->id)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('created_at', 'asc')
            ->get();

        $remaining = $amount;

        foreach ($transactions as $transaction) {
            $unusedTransactionPoints = $transaction->points - $transaction->points_used;

            if ($unusedTransactionPoints <= 0 || $remaining <= 0) {
                continue;
            }

            $pointsToUse = min($remaining, $unusedTransactionPoints);

            $transaction->points_used += $pointsToUse;
            $transaction->save();

            $remaining -= $pointsToUse;

            if ($remaining <= 0) {
                break;
            }
        }
    }

    /**
     * Adds a purchase or points to the system, creating a new Transaction record.
     *
     * @param string $member_identifier The identifier for the member.
     * @param string $card_identifier The identifier for the card.
     * @param Staff $staff The staff user adding the purchase.
     * @param float|null $purchase_amount The amount of the purchase, or null.
     * @param float|null $points The number of points to add, or null.
     * @param string|null $image The image associated with the transaction.
     * @param string|null $note Any notes to attach to the transaction.
     * @param bool $points_only Determines if the transaction is points-only.
     * @param string|null $created_at The date and time of the transaction.
     *
     * @return Transaction The transaction that was created.
     */
    public function addPurchase(
        string $member_identifier, 
        string $card_identifier, 
        Staff $staff, 
        ?float $purchase_amount, 
        ?float $points,
        string $image = null, 
        string $note = null, 
        bool $points_only,
        string $created_at = null
    ): Transaction {
        // Fetch member and card details
        $member = $this->memberService->findActiveByIdentifier($member_identifier);
        $card = $this->cardService->findActiveCardByIdentifier($card_identifier);
        $partner = $card->partner;
        $created_at = $created_at ?? Carbon::now('UTC');

        // Check if staff has access to card
        if (!$staff->isRelatedToCard($card)) {
            abort(401);
        }

        // Set expiration date based on date of creation
        $expires_at = (!$created_at instanceof Carbon) ? Carbon::parse($created_at) : $created_at;
    
        // Data for transaction record
        $data = [
            'staff_id' => $staff->id,
            'member_id' => $member->id,
            'card_id' => $card->id,
            'partner_name' => $partner->name,
            'partner_email' => $partner->email,
            'staff_name' => $staff->name,
            'staff_email' => $staff->email,
            'card_title' => $card->getTranslations('head'),
            'currency' => $card->currency,
            'points_per_currency' => $card->points_per_currency,
            'min_points_per_purchase' => $card->min_points_per_purchase,
            'max_points_per_purchase' => $card->max_points_per_purchase,
            'expires_at' => $expires_at->addMonths($card->points_expiration_months)->format('Y-m-d H:i:s'),
            'created_by' => $partner->id,
        ];
    
        if ($points_only) {
            $number_of_points_issued = $points;
            $data['purchase_amount'] = null;
            $purchase_amount_parsed = 0;
        } else {
            // Parse $purchase_amount to an integer for database storage
            $currencies = new ISOCurrencies();
            $moneyParser = new DecimalMoneyParser($currencies);
            $purchase_amount_parsed = $moneyParser->parse((string)$purchase_amount, new Currency($card->currency))->getAmount();
    
            // Calculate points based on $purchase_amount
            $points = $card->calculatePoints($purchase_amount);
            $number_of_points_issued = $points;
            $data['purchase_amount'] = $purchase_amount_parsed;
        }

        // Check if this is first transaction and if bonus points are configured
        if ($card->initial_bonus_points && !Transaction::where('member_id', $member->id)->where('card_id', $card->id)->exists()) {
            $bonusData = array_merge($data, [
                'points' => $card->initial_bonus_points,
                'event' => 'initial_bonus_points',
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ]);
            $transaction = Transaction::create($bonusData);

            $number_of_points_issued += $card->initial_bonus_points;

            // Add analytics
            $this->analyticsService->addIssueAnalytic($card, $staff, $member, $card->initial_bonus_points, $card->currency, 0, $created_at);

            // Add a second to the created_at timestamp for sorting purposes
            if (!$created_at instanceof Carbon) {
                $created_at = Carbon::parse($created_at);
            }
            $created_at->addSecond();
        }

        // Prepare data for new transaction record
        $purchaseData = array_merge($data, [
            'points' => $points,
            'event' => $points_only ? 'staff_credited_points' : 'staff_credited_points_for_purchase',
            'note' => $note,
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ]);

        // Create a new transaction record
        $transaction = Transaction::create($purchaseData);

        // Attach image if present
        if ($image) {
            $transaction->addMediaFromRequest('image')->toMediaCollection('image');
        }

        // Update stats
        if (!$points_only) $card->total_amount_purchased += $purchase_amount_parsed;
        $card->number_of_points_issued += $number_of_points_issued;
        $card->last_points_issued_at = Carbon::now('UTC');
        $card->save();

        // Add analytics
        $this->analyticsService->addIssueAnalytic($card, $staff, $member, $points, $card->currency, $purchase_amount_parsed, $created_at);

        // Send mail
        $member->notify(new PointsReceived($member, $points, $card));

        return $transaction;
    }

    /**
     * Redeem points for reward, creating a new Transaction record.
     *
     * @param int $card_id
     * @param int $reward_id
     * @param string $member_identifier
     * @param Staff $staff
     * @param string|null $image
     * @param string|null $note
     * @param string|null $created_at
     * 
     * @return Transaction|bool
     */
    public function claimReward(
        int $card_id, 
        int $reward_id, 
        string $member_identifier, 
        Staff $staff, 
        string $image = null, 
        string $note = null, 
        string $created_at = null
    ): Transaction|bool {
        // Fetch member and card details
        $card = $this->cardService->findActiveCard($card_id);
        $reward = $this->rewardService->findActiveReward($reward_id);
        $member = $this->memberService->findActiveByIdentifier($member_identifier);
        $partner = $card->partner;

        // Check if staff has access to card
        if (!$staff->isRelatedToCard($card)) {
            abort(401);
        }

        if ($card->getMemberBalance($member) < $reward->points) {
            return false;
        }

        $this->consumeMemberCardPointsFifo($member, $card, $reward->points);

        // Data for transaction record
        $data = [
            'staff_id' => $staff->id,
            'member_id' => $member->id,
            'card_id' => $card->id,
            'reward_id' => $reward->id,
            'partner_name' => $partner->name,
            'partner_email' => $partner->email,
            'staff_name' => $staff->name,
            'staff_email' => $staff->email,
            'card_title' => $card->getTranslations('head'),
            'reward_title' => $reward->getTranslations('title'),
            'reward_points' => $reward->points,
            'currency' => $card->currency,
            'event' => 'staff_redeemed_points_for_reward',
            'points' => -$reward->points,
            'note' => $note,
            'points_per_currency' => $card->points_per_currency,
            'min_points_per_purchase' => $card->min_points_per_purchase,
            'max_points_per_purchase' => $card->max_points_per_purchase,
            'created_by' => $partner->id,
            'created_at' => $created_at ?? Carbon::now('UTC'),
            'updated_at' => $created_at ?? Carbon::now('UTC'),
        ];

        // Create a new transaction record
        $transaction = Transaction::create($data);

        // Attach image if present
        if ($image) {
            $transaction->addMediaFromRequest('image')->toMediaCollection('image');
        }

        // Update stats
        $card->number_of_points_redeemed += $reward->points;
        $card->number_of_rewards_redeemed += 1;
        $card->last_reward_redeemed_at = Carbon::now('UTC');
        $card->save();

        // Add analytics
        $this->analyticsService->addClaimRewardAnalytic($card, $staff, $member, $reward, $created_at);

        // Send mail
        if (!$created_at) $member->notify(new RewardClaimed($member, $reward->points, $card, $reward));

        return $transaction;
    }

    /**
     * Remove points from a member's balance (staff adjustment), using the same FIFO rules as reward redemption.
     *
     * @return Transaction|bool False if the member does not have enough points.
     */
    public function deductPoints(
        string $member_identifier,
        string $card_identifier,
        Staff $staff,
        int $points,
        $image = null,
        ?string $note = null
    ): Transaction|bool {
        $member = $this->memberService->findActiveByIdentifier($member_identifier);
        $card = $this->cardService->findActiveCardByIdentifier($card_identifier);
        $partner = $card->partner;

        if (!$staff->isRelatedToCard($card)) {
            abort(401);
        }

        if ($points < 1 || $card->getMemberBalance($member) < $points) {
            return false;
        }

        return DB::transaction(function () use ($member, $card, $staff, $partner, $points, $image, $note) {
            $this->consumeMemberCardPointsFifo($member, $card, $points);

            $now = Carbon::now('UTC');

            $data = [
                'staff_id' => $staff->id,
                'member_id' => $member->id,
                'card_id' => $card->id,
                'partner_name' => $partner->name,
                'partner_email' => $partner->email,
                'staff_name' => $staff->name,
                'staff_email' => $staff->email,
                'card_title' => $card->getTranslations('head'),
                'currency' => $card->currency,
                'event' => 'staff_debited_points',
                'points' => -$points,
                'note' => $note,
                'points_per_currency' => $card->points_per_currency,
                'min_points_per_purchase' => $card->min_points_per_purchase,
                'max_points_per_purchase' => $card->max_points_per_purchase,
                'created_by' => $partner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $transaction = Transaction::create($data);

            if ($image) {
                $transaction->addMediaFromRequest('image')->toMediaCollection('image');
            }

            $card->number_of_points_redeemed += $points;
            $card->save();

            $this->analyticsService->addStaffDebitAnalytic($card, $staff, $member, $points);

            return $transaction;
        });
    }
}