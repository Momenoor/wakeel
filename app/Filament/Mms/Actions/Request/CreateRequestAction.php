<?php

namespace App\Filament\Mms\Actions\Request;

use App\Enums\AttachmentCategory;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Support\AttachmentFields;
use App\Filament\Support\OneDriveFilePicker;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Services\MMS\Requests\RequestServiceFactory;
use App\Support\RequestMemo;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CreateRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'add_request';
    }

    public function setUp(): void
    {
        parent::setUp();
        $this
            ->label(__('Add Request'))
            ->icon('heroicon-o-plus')
            ->visible(fn ($record) => auth()->user()->can('Create:MatterRequest') || auth()->user()->can('CreateRequest:Matter'))
            ->modalHeading(__('Submit New Request'))
            ->successNotificationTitle(__('Request submitted successfully.'))
            ->action(function (array $data, $record, $component) {
                // From OneDrive: fetched first — one that can't be had stops
                // the request before anything is saved.
                $fromOneDrive = OneDriveFilePicker::fetch($record, array_values((array) ($data['onedrive_files'] ?? [])));

                $type = $data['type'];
                $service = RequestServiceFactory::classFor($type);
                $prepared = $service::prepareForCreation($data, $record);

                $request = $record->requests()->create([
                    'request_by' => auth()->id(),
                    'type' => $type,
                    'status' => 'pending',
                    'comment' => $prepared['comment'],
                    'extra' => $prepared['extra'],
                ]);

                // The uploads, and — kept with the request as they are now — the files from OneDrive.
                AttachmentFields::attach($request, [...array_values((array) ($data['attachments'] ?? [])), ...$fromOneDrive], AttachmentCategory::REQUEST);

                $requestService = RequestServiceFactory::make($request);
                $requestService->afterCreated();
                $requestService->onCreateNotify();
                $requestService->refresh($component);
            });
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('Request Type'))
                ->options(RequestType::class)
                ->required()
                // A type already asked for (and not rejected) can't be asked again; the final report's
                // confirmation only once the initial report's review is approved.
                ->disableOptionWhen(fn (string $value, Matter $record): bool => ! in_array($value, self::allowedTypes($record), true))
                ->helperText(fn (Matter $record): ?string => self::awaitsReview($record)
                    ? __('Confirm Report can be asked for once a Review Report request on this matter is approved.')
                    : null)
                ->live()
                // The type's own fields (new difficulty, new date …) filled
                // as they appear — Filament's way for fields that depend on
                // another. Their state did not exist before, so a dropdown
                // picked there was never sent: "New Difficulty is required"
                // with one chosen.
                ->afterStateUpdated(fn (Select $component) => $component->getContainer()->getComponent('typeFields')?->getChildSchema()->fill()),
            Group::make()
                ->key('typeFields')
                ->schema(fn (Get $get) => $get('type')
                    ? RequestServiceFactory::classFor($get('type'))::createFormFields()
                    : [])
                ->columnSpanFull(),
            Textarea::make('comment')->label(__('Comment'))->required()->rows(3),
            // Required by the type — unless one comes from OneDrive.
            AttachmentFields::upload('attachments')
                ->label(__('Attachments'))
                ->required(fn (Get $get) => $get('type') && RequestServiceFactory::classFor($get('type'))::requiresAttachmentsOnCreate() && blank($get('onedrive_files'))),
            OneDriveFilePicker::field($this->getRecord() instanceof Matter ? $this->getRecord() : null)
                ->helperText(__('Files from this matter\'s OneDrive folder, kept with the request as they are now.')),
        ]);
    }

    /**
     * The types that can be asked for on the matter now: not one already asked for and not rejected, and the
     * final report's confirmation only after an approved review of the initial report.
     *
     * @return list<string>
     */
    public static function allowedTypes(Matter $matter): array
    {
        $state = self::state($matter);

        return collect(RequestType::cases())
            ->map(fn (RequestType $type): string => $type->value)
            ->reject(fn (string $type): bool => in_array($type, $state['open'], true) || ($type === RequestType::CONFIRM_REPORT->value && ! $state['reviewed']))
            ->values()
            ->all();
    }

    /** Confirm Report not open yet only for want of an approved review of the initial report. */
    public static function awaitsReview(Matter $matter): bool
    {
        $state = self::state($matter);

        return ! $state['reviewed'] && ! in_array(RequestType::CONFIRM_REPORT->value, $state['open'], true);
    }

    /**
     * The matter's requests, read once: the types asked for and not rejected, and whether a review of the
     * initial report was approved.
     *
     * @return array{open: list<string>, reviewed: bool}
     */
    private static function state(Matter $matter): array
    {
        return RequestMemo::remember('matter-request-types.'.$matter->getKey(), function () use ($matter): array {
            $requests = $matter->requests()->get(['type', 'status']);

            return [
                'open' => $requests->reject(fn (MatterRequest $request): bool => $request->status === RequestStatus::REJECTED)
                    ->map(fn (MatterRequest $request): string => $request->type->value)
                    ->values()
                    ->all(),
                'reviewed' => $requests->contains(fn (MatterRequest $request): bool => $request->type === RequestType::REVIEW_REPORT && $request->status === RequestStatus::APPROVED),
            ];
        });
    }
}
