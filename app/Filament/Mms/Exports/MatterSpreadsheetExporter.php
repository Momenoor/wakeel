<?php

namespace App\Filament\Mms\Exports;

use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\InvalidArgumentException;
use OpenSpout\Writer\Exception\InvalidSheetNameException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * What the matter spreadsheets share: the "completed" notification, the
 * Arial cell and the dark-blue header styles, and a sheet with its header row
 * frozen. Each export gives its sheet's name and its own columns and widths.
 */
abstract class MatterSpreadsheetExporter extends Exporter
{
    /** The worksheet's name. */
    abstract protected function sheetName(): string;

    /** Right-to-left sheet; off unless the export asks for it. */
    protected function sheetRightToLeft(): bool
    {
        return false;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = trans_choice('export_completed', $export->successful_rows, ['count' => Number::format($export->successful_rows)]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.trans_choice('export_failed', $failedRowsCount, ['count' => Number::format($failedRowsCount)]);
        }

        return $body;
    }

    public function getXlsxCellStyle(): ?Style
    {
        return new Style()
            ->setFontSize(11)
            ->setShouldWrapText()
            ->setFontName('Arial');
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getXlsxHeaderCellStyle(): ?Style
    {
        return new Style()
            ->setFontBold()
            ->setShouldWrapText()
            ->setFontSize(11)
            ->setFontName('Arial')
            ->setFontColor(Color::rgb(255, 255, 255))
            ->setBackgroundColor(Color::rgb(31, 56, 100))
            ->setCellAlignment(CellAlignment::CENTER);
    }

    /**
     * @throws InvalidSheetNameException
     * @throws WriterNotOpenedException
     * @throws InvalidArgumentException
     */
    public function configureXlsxWriterBeforeClose(Writer $writer): Writer
    {
        $sheetView = new SheetView;
        $sheetView->setFreezeRow(2);

        if ($this->sheetRightToLeft()) {
            $sheetView->setRightToLeft(true);
        }

        $sheet = $writer->getCurrentSheet();
        $sheet->setSheetView($sheetView);
        $sheet->setName($this->sheetName());

        return $writer;
    }
}
