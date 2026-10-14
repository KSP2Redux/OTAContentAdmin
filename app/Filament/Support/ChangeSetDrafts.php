<?php

namespace App\Filament\Support;

use App\Models\ChangeSet;
use App\Services\Publishing\ChangeSetCreator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ChangeSetDrafts
{
    public static function summaryField(): TextInput
    {
        return TextInput::make('summary')->label('Summary')->required()->maxLength(255)
            ->helperText('Describe the player-visible content change.');
    }

    public static function options(): array
    {
        return ChangeSet::query()->whereIn('state', ['draft', 'validated'])->latest()->pluck('summary', 'id')->all();
    }

    public static function select(): Select
    {
        return Select::make('change_set_id')->label('Draft change set')->placeholder('Choose a draft change set')
            ->options(fn (): array => self::options())->required()
            ->createOptionForm([self::summaryField()])
            ->createOptionUsing(fn (array $data): string => self::create($data)->getKey())
            ->createOptionAction(fn (Action $action): Action => self::dialog($action)->button()->size('sm'));
    }

    public static function action(string $name, ?Closure $afterCreate = null): Action
    {
        return self::dialog(Action::make($name))->schema([self::summaryField()])
            ->action(function (array $data) use ($afterCreate): void {
                $changeSet = self::create($data);
                if ($afterCreate) {
                    $afterCreate($changeSet);
                }
            });
    }

    private static function dialog(Action $action): Action
    {
        return $action->label('Create change set')->icon('heroicon-m-plus')
            ->modalHeading('Create change set')->modalWidth('md')
            ->modalDescription('Create a draft, then continue preparing your changes here.')
            ->modalSubmitActionLabel('Create change set');
    }

    public static function create(array $data, bool $notify = true): ChangeSet
    {
        try {
            $changeSet = app(ChangeSetCreator::class)->create($data['summary']);
        } catch (HttpException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Notification::make()->title('Could not create change set')
                ->body('The content revision could not be read or the draft could not be saved. Please try again.')
                ->danger()->send();
            throw new Halt;
        }

        if ($notify) {
            Notification::make()->title('Change set created')->success()->send();
        }

        return $changeSet;
    }
}
