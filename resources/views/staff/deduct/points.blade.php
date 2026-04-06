@extends('staff.layouts.default')

@section('page_title', $card->head . config('default.page_title_delimiter') . trans('common.remove_points_from_balance') . config('default.page_title_delimiter') . config('default.app_name'))

@section('content')
<div class="flex flex-col w-full p-6">
    <div class="space-y-6 h-full w-full place-items-center">
        <div class="max-w-md mx-auto">
            @if($member && $card)
            <x-forms.messages />
            @if($balance < 1)
                <div class="mb-6 format format-sm sm:format-base lg:format-md dark:format-invert">
                    <p class="text-gray-600 dark:text-gray-400">{{ trans('common.insufficient_points_to_remove') }}</p>
                </div>
            @else
            <x-forms.form-open action="{{ route('staff.deduct.points.post', ['member_identifier' => $member->unique_identifier, 'card_identifier' => $card->unique_identifier]) }}" enctype="multipart/form-data" method="POST" />
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    {{ trans('common.balance') }}: <span class="font-semibold format-number">{{ $balance }}</span>
                </p>
                <div class="grid gap-4 sm:col-span-2 md:gap-6 sm:grid-cols-1 mb-6">
                    <x-forms.input
                        name="points"
                        value=""
                        inputmode="numeric"
                        :label="trans('common.points')"
                        type="number"
                        icon="coins"
                        affix-class="text-gray-400 dark:text-gray-500 text-xl"
                        input-class="text-xl"
                        :min="1"
                        :max="$balance"
                        step="1"
                        placeholder="0"
                        :required="true"
                    />
                </div>

                <div class="grid gap-4 sm:col-span-2 md:gap-6 sm:grid-cols-1 mb-6">
                    <x-forms.image
                        type="image"
                        capture="environment"
                        icon="camera"
                        name="image"
                        :placeholder="trans('common.add_photo')"
                        accept="image/*"
                    />
                </div>

                <div class="grid gap-4 sm:col-span-2 md:gap-6 sm:grid-cols-1 mb-6">
                    <x-forms.input
                        name="note"
                        value=""
                        type="text"
                        input-class="text-xl"
                        :placeholder="trans('common.optional_note')"
                        :required="false"
                    />
                </div>

                <div class="mb-6">
                    <button type="submit" class="btn-primary btn-lg w-full h-16">{{ trans('common.remove_points_from_balance') }}</button>
                </div>
            <x-forms.form-close />
            <script>
            document.querySelector('form').addEventListener('submit', function() {
                this.querySelector('button[type="submit"]').disabled = true;
            });
            </script>
            @endif
            @endif

            @if($member)
               <x-member.member-card class="mb-6" :member="$member" />
            @else
                <div class="mb-6 format format-sm sm:format-base lg:format-md dark:format-invert">
                    <h3>{{ trans('common.member_not_found') }}</h3>
                </div>
            @endif

            @if($card)
                <x-member.card
                    :card="$card"
                    :member="$member"
                    :flippable="false"
                    :links="false"
                    :show-qr="false"
                />
                @if($member)
                <div class="mt-4 flex flex-col gap-2">
                    <a href="{{ route('staff.earn.points', ['member_identifier' => $member->unique_identifier, 'card_identifier' => $card->unique_identifier]) }}" class="flex items-center text-link">
                        <x-ui.icon icon="arrow-small-left" class="w-5 h-5 mr-2"/>
                        {{ trans('common.add_points_to_balance') }}
                    </a>
                    <a href="{{ route('member.card', ['card_id' => $card->id]) }}" target="_blank" class="flex items-center text-link">
                        <x-ui.icon icon="arrow-top-right-on-square" class="w-5 h-5 mr-2"/>
                        {{ trans('common.view_card_on_website') }}
                    </a>
                </div>
                @endif
            @else
                <div class="format format-sm sm:format-base lg:format-md dark:format-invert">
                    <h3>{{ trans('common.card_not_found') }}</h3>
                </div>
            @endif
        </div>
    </div>
</div>
@stop
