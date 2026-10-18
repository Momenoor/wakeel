<?php

namespace App\Filament\Mms\Actions\Request;

use App\Enums\AttachmentCategory;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Support\AttachmentFields;
use App\Filament\Support\OneDriveFilePicker;
use App\Models\Matter;
use App\Services\MMS\Requests\RequestServiceFactory;
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
                // A type already asked for (and not rejected) can't be asked
                // again — the matter's open types read once, not one query a type.
                ->disableOptionWhen(function (string $value, $record): bool {
                    $key = 'matter_request_types_'.$record->getKey();
                    $request = request();

                    if (! $request->attributes->has($key)) {
                        $request->attributes->set($key, $record->requests()->whereNot('status', RequestStatus::REJECTED)->pluck('type')
                            ->map(fn ($type) => $type instanceof \BackedEnum ? (string) $type->value : (string) $type)
                            ->all());
                    }

                    return in_array($value, $request->attributes->get($key), true);
                })
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
}
