<?php
declare(strict_types=1);

namespace App\Workflow;

/**
 * Zentrale Definition & Utilities für Workflow-Status-Tags.
 *
 * MAP:
 *   State-Key  => Anzeigename (Paperless-Tag)
 *
 * Beispiele:
 *   keyToName('APP_OK')           -> 'WF:Rechnungsfreigabe_erfolgt'
 *   nameToKey('WF:Pruefen')       -> 'PRUEFEN'
 *   isValidKey('SEPA')            -> true
 *   isValidName('WF:Wiedervorlage')-> true
 */
final class StateTags
{
    /** @var array<string,string> */
    public const MAP = [
        'INIT'    => 'WF:Init',
        'PRUEFEN' => 'WF:Pruefen',
        'PRUEFEN2'=> 'WF:Wiedervorlage',
        'UNVOLL'  => 'WF:Daten_unvollständig',
        'APP_REQ' => 'WF:Rechnungsfreigabe_erforderlich',
        'APP_OK'  => 'WF:Rechnungsfreigabe_erfolgt',
        'APP_REJ' => 'WF:Freigabe_verweigert',
        'SEPA'    => 'WF:SEPA_erzeugt',
        'CLOSE'   => 'WF:Abgeschlossen',
        'ERROR'   => 'WF:Error',
        'IGNORE'  => 'WF:Ignorieren',
        'REACT'   => 'WF:Reaktivieren',
    ];

    /** Direkt: State-Key → Anzeigename, oder null wenn unbekannt. */
    public static function keyToName(string $stateKey): ?string
    {
        $k = strtoupper(trim($stateKey));
        return self::MAP[$k] ?? null;
    }

    /**
     * Anzeigename → State-Key (ohne Aliases).
     * Vergleicht normalisiert (Whitespace, Case).
     */
    public static function nameToKey(string $tagDisplayName): ?string
    {
        $needle = self::norm($tagDisplayName);
        foreach (self::MAP as $key => $name) {
            if (self::norm($name) === $needle) {
                return $key;
            }
        }
        return null;
    }

    /** Gibt alle bekannten State-Keys zurück. @return string[] */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    /** Gibt alle Anzeigenamen zurück. @return string[] */
    public static function names(): array
    {
        return array_values(self::MAP);
    }

    /** Ist das ein bekannter State-Key? */
    public static function isValidKey(string $stateKey): bool
    {
        return isset(self::MAP[strtoupper(trim($stateKey))]);
    }

    /** Ist das ein bekannter Anzeigename? (normalisierter Vergleich) */
    public static function isValidName(string $tagDisplayName): bool
    {
        return self::nameToKey($tagDisplayName) !== null;
    }

    /** interne Normalisierung für vergleichende Operationen */
    private static function norm(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        // Leerzeichen/Unterstrich/Bindestrich entfernen
        $s = str_replace([' ', '_', '-'], '', $s);
        return $s;
    }
}
