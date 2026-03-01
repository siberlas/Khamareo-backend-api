<?php

namespace App\Shipping\Service;

use Psr\Log\LoggerInterface;

/**
 * Service pour valider les adresses avant sauvegarde
 */
class AddressValidationService
{
    public function __construct(
        private AdresseApiService $adresseApi,
        private MapboxGeocodingService $mapbox,
        private LoggerInterface $logger,
        private bool $strictMode = false,
    ) {}

    /**
     * Valide une adresse en reverse geocode
     * Retourne TRUE si l'adresse existe, FALSE sinon
     */
    public function validateAddress(
        string $street,
        string $postalCode,
        string $city,
        string $country = 'FR',
        ?float $lat = null,
        ?float $lon = null,
        ?bool $strict = null
    ): array {
        $country = $this->normalizeCountry($country);
        $strict = $strict ?? $this->strictMode;
        $this->logger->info('Address validation', [
            'street' => $street,
            'postcode' => $postalCode,
            'city' => $city,
            'country' => $country,
            'lat' => $lat,
            'lon' => $lon,
            'strict' => $strict,
        ]);

        // Si on a les coordonnées, faire un reverse geocode pour confirmer
        if ($lat !== null && $lon !== null) {
            return $this->validateByCoordinates($lat, $lon, $country, $strict);
        }

        // Sinon, essayer de matcher l'adresse saisie
        return $this->validateByAddress($street, $postalCode, $city, $country, $strict);
    }

    /**
     * Valide via reverse geocode des coordonnées
     */
    private function validateByCoordinates(float $lat, float $lon, string $country, bool $strict): array
    {
        // France ou DOM-TOM
        if (strtoupper($country) === 'FR') {
            $result = $this->adresseApi->reverseGeocode($lat, $lon);
            if ($result) {
                $this->logger->info('Address validated by coords (Adresse API)', [
                    'address' => $result['address'] ?? 'N/A',
                ]);
                return [
                    'valid' => true,
                    'message' => 'Adresse validée',
                    'source' => 'adresse',
                    'normalized' => $result,
                ];
            }
        }

        // International via Mapbox
        $result = $this->mapbox->reverseGeocode($lat, $lon);
        if ($result) {
            $this->logger->info('Address validated by coords (Mapbox)', [
                'address' => $result['address'] ?? 'N/A',
            ]);
            return [
                'valid' => true,
                'message' => 'Adresse validée',
                'source' => 'mapbox',
                'normalized' => $result,
            ];
        }

        $this->logger->warning('Address validation failed - no address found for coordinates', [
            'lat' => $lat,
            'lon' => $lon,
        ]);
        if (!$strict) {
            return [
                'valid' => true,
                'message' => 'Adresse acceptée sans validation stricte',
                'source' => null,
                'normalized' => [
                    'lat' => $lat,
                    'lon' => $lon,
                ],
            ];
        }
        return [
            'valid' => false,
            'message' => 'Aucune adresse ne correspond à ces coordonnées',
            'source' => null,
            'normalized' => null,
        ];
    }

    /**
     * Valide en cherchant une adresse similaire
     * Stratégie France: Adresse API d'abord (score >= 0.7), puis Mapbox en fallback
     */
    private function validateByAddress(string $street, string $postalCode, string $city, string $country, bool $strict): array
    {
        $query = trim(sprintf('%s %s %s', $street, $postalCode, $city));
        if (!$strict) {
            $this->logger->info('Address validation in lenient mode', [
                'query' => $query,
                'country' => $country,
            ]);
        }

        // ✅ FRANCE: essayer Adresse API d'abord avec score strict, fallback Mapbox
        if (strtoupper($country) === 'FR') {
            // 1️⃣ TENTATIVE 1: Adresse API (stricte - score >= 0.7)
            $results = $this->adresseApi->autocomplete($query, 5);
            if (!empty($results)) {
                $match = $results[0];
                $score = $match['raw']['properties']['score'] ?? 0;
                $matchLabel = $match['label'] ?? 'N/A';
                
                $this->logger->info('📍 Adresse API search score', [
                    'query' => $query,
                    'score' => $score,
                    'match' => $matchLabel,
                ]);
                
                // 🔍 VALIDATION: Vérifier que le match contient au moins le code postal ou la ville
                // ET que la rue saisie (si fournie) apparaisse dans le résultat
                $postalCodeInMatch = stripos($matchLabel, $postalCode) !== false;
                $cityInMatch = stripos($matchLabel, $city) !== false;

                // Détecter si l'utilisateur a fourni une vraie rue (pas vide, pas seulement nombre)
                $streetProvided = trim($street) !== '' && preg_match('/[A-Za-zÀ-ÖØ-öø-ÿ]{2,}/', $street);

                // Vérifier que la rue fournie a au minimum 2 termes significatifs (ex: "5 Rue de la Paix")
                // Rejeter les cas type "5 rue", "4 Rue ", "1 Rue" (incomplets, non livrables)
                $streetTokenCount = 0;
                $significantStreetTokens = 0;
                if ($streetProvided) {
                    $normalizedStreet = $this->normalizeStreetForSearch($street, $postalCode, $city);
                    $streetTokens = preg_split('/[^\p{L}0-9]+/u', $normalizedStreet, -1, PREG_SPLIT_NO_EMPTY);
                    $streetTokenCount = count($streetTokens);
                    
                    // Compter les tokens non-numériques de 3+ lettres
                    foreach ($streetTokens as $token) {
                        if (mb_strlen($token) >= 3 && !preg_match('/^\d+$/', $token)) {
                            $significantStreetTokens++;
                        }
                    }
                    
                    // ❌ REJETER IMMÉDIATEMENT si rue trop incomplète (ex: "5 rue" = 1 token significatif)
                    if ($strict && $significantStreetTokens < 2) {
                        $this->logger->warning('❌ [VALIDATION] Street too vague - REJECTING immediately', [
                            'query' => $query,
                            'street_input' => $street,
                            'token_count' => $streetTokenCount,
                            'significant_tokens' => $significantStreetTokens,
                            'strict' => $strict,
                        ]);
                        return [
                            'valid' => false,
                            'message' => 'Adresse incomplète - veuillez fournir le nom complet de la rue',
                            'source' => null,
                            'normalized' => null,
                        ];
                    }

                    // 🔍 Vérifier que la rue existe dans la ville via l'API Adresse (strict uniquement)
                    if ($strict) {
                        $streetForSearch = $normalizedStreet;
                        $streetResults = $this->adresseApi->searchStreets($streetForSearch, $postalCode, $city, 10);
                        $streetMatchFound = false;
                        foreach ($streetResults as $result) {
                            $label = $result['label'] ?? '';
                            foreach ($streetTokens as $token) {
                                if (mb_strlen($token) < 3) {
                                    continue;
                                }
                                if (stripos($label, $token) !== false) {
                                    $streetMatchFound = true;
                                    break 2;
                                }
                            }
                        }

                        if (!$streetMatchFound) {
                            $this->logger->warning('❌ [VALIDATION] Street not found in city via Adresse API', [
                                'street_input' => $street,
                                'street_search' => $streetForSearch,
                                'postalCode' => $postalCode,
                                'city' => $city,
                                'strict' => $strict,
                            ]);
                            return [
                                'valid' => false,
                                'message' => 'Adresse invalide - rue introuvable dans cette ville',
                                'source' => null,
                                'normalized' => null,
                            ];
                        }
                    }

                    // ❌ REJETER si le nom de rue est probablement du gibberish (ex: "Avenue uoluoluluiluil")
                    if ($strict) {
                        $streetTypeWords = [
                            'rue','avenue','av','boulevard','bd','chemin','route','impasse','place','allee','allée',
                            'lotissement','cours','quai','square','cite','cité','passage','voie','faubourg','rd','rn'
                        ];

                        $meaningfulTokenFound = false;
                        foreach ($streetTokens as $token) {
                            $lower = mb_strtolower($token);
                            if (preg_match('/^\d+$/', $lower)) {
                                continue;
                            }
                            if (in_array($lower, $streetTypeWords, true)) {
                                continue;
                            }
                            if (mb_strlen($lower) < 3) {
                                continue;
                            }
                            // Heuristique: ratio de caractères uniques pour éviter les suites de lettres incohérentes
                            $chars = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
                            $uniqueRatio = count(array_unique($chars)) / max(1, count($chars));
                            if ($uniqueRatio >= 0.4) {
                                $meaningfulTokenFound = true;
                                break;
                            }
                        }

                        if (!$meaningfulTokenFound) {
                            $this->logger->warning('❌ [VALIDATION] Street name looks gibberish - REJECTING', [
                                'query' => $query,
                                'street_input' => $street,
                                'strict' => $strict,
                            ]);
                            return [
                                'valid' => false,
                                'message' => 'Adresse invalide - nom de rue non reconnu',
                                'source' => null,
                                'normalized' => null,
                            ];
                        }
                    }
                }

                // Vérifier correspondance minimale de la rue : au moins un token de 3+ lettres présent
                $streetTokenMatch = false;
                if ($streetProvided && $significantStreetTokens >= 2) {
                    $tokens = preg_split('/[^\p{L}0-9]+/u', $street, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($tokens as $token) {
                        if (mb_strlen($token) < 3) {
                            continue;
                        }
                        if (stripos($matchLabel, $token) !== false) {
                            $streetTokenMatch = true;
                            break;
                        }
                    }
                }

                // Condition d'acceptation initiale : city ou postal présent
                if ($postalCodeInMatch || $cityInMatch) {
                    // Si l'utilisateur a fourni une rue, exiger qu'elle apparaisse dans le match
                    if ($streetProvided && !$streetTokenMatch) {
                        $this->logger->warning('❌ [ADRESSE API] Street provided but not found in match (rejecting)', [
                            'query' => $query,
                            'input_street' => $street,
                            'match' => $matchLabel,
                            'score' => $score,
                        ]);
                        // Ne pas accepter ce résultat : on tombera ensuite sur le fallback Mapbox
                    } else {
                        // 🔍 VALIDATION SUPPLÉMENTAIRE: Vérifier que le match est plus complet que l'input
                        // Évite d'accepter des adresses partielles (ex: "5 rue" non livrable)
                        $matchLength = mb_strlen($matchLabel);
                        $queryLength = mb_strlen($query);
                        
                        // Si le match est moins long que 50% de la query, c'est qu'on a perdu de l'info → REJETER
                        if ($matchLength < $queryLength * 0.5) {
                            $this->logger->warning('❌ [ADRESSE API] Match is too short compared to input (incomplete address)', [
                                'query' => $query,
                                'query_length' => $queryLength,
                                'match' => $matchLabel,
                                'match_length' => $matchLength,
                            ]);
                            // Essayer fallback en continuant (pas de return)
                        } else {
                            // ✅ VALIDATION: score >= 0.7 (70% de correspondance minimum)
                            if ($score >= 0.7) {
                                $this->logger->info('✅ [ADRESSE API] Address validated with high score', [
                                    'query' => $query,
                                    'match' => $matchLabel,
                                    'score' => $score,
                                ]);
                                return [
                                    'valid' => true,
                                    'message' => 'Adresse validée',
                                    'source' => 'adresse',
                                    'normalized' => [
                                        'street' => $matchLabel,
                                        'postalCode' => $match['postalCode'] ?? $match['postcode'] ?? $postalCode,
                                        'city' => $match['city'] ?? $city,
                                        'lat' => $match['lat'] ?? null,
                                        'lon' => $match['lon'] ?? null,
                                    ],
                                ];
                            }

                            // Score acceptable (0.4-0.7) - log warning mais accepter (avec validation match)
                            if ($score >= 0.4) {
                                $this->logger->warning('⚠️ [ADRESSE API] Address with low score (fallback accepted)', [
                                    'query' => $query,
                                    'score' => $score,
                                    'match' => $matchLabel,
                                ]);
                                return [
                                    'valid' => true,
                                    'message' => 'Adresse validée (score modéré)',
                                    'source' => 'adresse',
                                    'normalized' => [
                                        'street' => $matchLabel,
                                        'postalCode' => $match['postalCode'] ?? $match['postcode'] ?? $postalCode,
                                        'city' => $match['city'] ?? $city,
                                        'lat' => $match['lat'] ?? null,
                                        'lon' => $match['lon'] ?? null,
                                    ],
                                ];
                            }

                            // Score très faible (< 0.4) - pas bon, essayer Mapbox
                            $this->logger->info('⏭️ [ADRESSE API] Score too low, trying Mapbox fallback', [
                                'query' => $query,
                                'score' => $score,
                            ]);
                        }
                    }
                } else {
                    // Match trop pauvre (ni code postal ni ville) -> essayer Mapbox fallback
                    $this->logger->warning('❌ [ADRESSE API] Match missing postal code AND city (gibberish detected), will try Mapbox', [
                        'query' => $query,
                        'match' => $matchLabel,
                        'score' => $score,
                    ]);
                }
            } else {
                // Aucun résultat Adresse API - essayer Mapbox
                $this->logger->info('⏭️ [ADRESSE API] No results, trying Mapbox fallback', [
                    'query' => $query,
                ]);
            }
            
            // 2️⃣ TENTATIVE 2: Mapbox en fallback
            // En mode strict: exiger numéro de rue + code postal
            $mapboxResults = $this->mapbox->autocomplete($query, $country, 5);
            if (!empty($mapboxResults)) {
                $match = $mapboxResults[0];
                $matchLabel = $match['label'] ?? 'N/A';
                
                // 🔍 Vérifier que Mapbox a trouvé une adresse VRAIE avec rue ET code postal
                // Rejeter si: pas de numéro de rue ET (pas de code postal OU pas assez de termes significatifs)
                $hasStreetNumber = preg_match('/^\d+\s/', $matchLabel) === 1;
                $postalCodeInMatch = stripos($matchLabel, $postalCode) !== false;
                
                // Compter les termes significatifs (mots de 3+ lettres)
                $significantTerms = 0;
                $tokens = preg_split('/[^\p{L}0-9]+/u', $matchLabel, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($tokens as $token) {
                    if (mb_strlen($token) >= 3 && !preg_match('/^\d+$/', $token)) {
                        $significantTerms++;
                    }
                }
                
                // Critères d'acceptation pour France fallback (strict uniquement):
                // Exiger ABSOLUMENT le numéro de rue + code postal
                if ($strict && !($hasStreetNumber && $postalCodeInMatch)) {
                    $this->logger->error('❌ [MAPBOX FALLBACK FR] Missing street number or postal code (Mapbox requires both)', [
                        'query' => $query,
                        'match' => $matchLabel,
                        'has_street_number' => $hasStreetNumber,
                        'has_postal_code' => $postalCodeInMatch,
                        'significant_terms' => $significantTerms,
                        'strict' => $strict,
                    ]);
                    return [
                        'valid' => false,
                        'message' => 'Adresse introuvable - veuillez vérifier et réessayer',
                        'source' => null,
                        'normalized' => null,
                    ];
                }
                $this->logger->warning('⚠️ [MAPBOX FALLBACK FR] Address accepted via Mapbox fallback', [
                    'query' => $query,
                    'match' => $matchLabel,
                    'source' => 'mapbox_fallback_fr',
                    'strict' => $strict,
                ]);
                return [
                    'valid' => true,
                    'message' => 'Adresse validée (via fallback Mapbox)',
                    'source' => 'mapbox_fallback_fr',
                    'normalized' => [
                        'street' => $street,
                        'postalCode' => $match['postalCode'] ?? $match['postcode'] ?? $postalCode,
                        'city' => $match['city'] ?? $city,
                        'lat' => $match['lat'] ?? null,
                        'lon' => $match['lon'] ?? null,
                    ],
                ];
            }
            
            // Aucune API n'a trouvé l'adresse
            $this->logger->error('❌ [FRANCE VALIDATION] Address not found in Adresse API or Mapbox', [
                'query' => $query,
                'country' => $country,
            ]);
            if (!$strict) {
                return [
                    'valid' => true,
                    'message' => 'Adresse acceptée sans validation stricte',
                    'source' => null,
                    'normalized' => [
                        'street' => $street,
                        'postalCode' => $postalCode,
                        'city' => $city,
                        'lat' => null,
                        'lon' => null,
                    ],
                ];
            }
            return [
                'valid' => false,
                'message' => 'Adresse introuvable - veuillez vérifier et réessayer',
                'source' => null,
                'normalized' => null,
            ];
        }

        // ❌ INTERNATIONAL: N'UTILISER QUE MAPBOX
        $results = $this->mapbox->autocomplete($query, $country, 5);
        if (!empty($results)) {
            $match = $results[0];
            $matchLabel = $match['label'] ?? 'N/A';

            if ($strict) {
                $normalizedStreet = $this->normalizeStreetForSearch($street, $postalCode, $city);
                $streetTokens = preg_split('/[^\p{L}0-9]+/u', $normalizedStreet, -1, PREG_SPLIT_NO_EMPTY);
                $streetTokenMatch = false;
                foreach ($streetTokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }
                    if (stripos($matchLabel, $token) !== false) {
                        $streetTokenMatch = true;
                        break;
                    }
                }

                $inputHasNumber = preg_match('/\b\d+\b/u', $street) === 1;
                $matchHasNumber = preg_match('/\b\d+\b/u', $matchLabel) === 1;

                if (!$streetTokenMatch || ($inputHasNumber && !$matchHasNumber)) {
                    $this->logger->warning('❌ [MAPBOX INTL] Street mismatch - rejecting', [
                        'query' => $query,
                        'match' => $matchLabel,
                        'street_tokens' => $streetTokens,
                        'input_has_number' => $inputHasNumber,
                        'match_has_number' => $matchHasNumber,
                        'strict' => $strict,
                    ]);
                    return [
                        'valid' => false,
                        'message' => 'Adresse invalide - rue introuvable dans cette ville',
                        'source' => null,
                        'normalized' => null,
                    ];
                }
            }

            $this->logger->info('✅ [MAPBOX INTL] Address validated', [
                'query' => $query,
                'country' => $country,
                'match' => $matchLabel,
            ]);
            return [
                'valid' => true,
                'message' => 'Adresse validée',
                'source' => 'mapbox',
                'normalized' => [
                    'street' => $street,
                    'postalCode' => $match['postalCode'] ?? $match['postcode'] ?? $postalCode,
                    'city' => $match['city'] ?? $city,
                    'lat' => $match['lat'] ?? null,
                    'lon' => $match['lon'] ?? null,
                ],
            ];
        }

        $this->logger->error('❌ Address validation failed - no address found in any service', [
            'query' => $query,
            'country' => $country,
        ]);
        if (!$strict) {
            return [
                'valid' => true,
                'message' => 'Adresse acceptée sans validation stricte',
                'source' => null,
                'normalized' => [
                    'street' => $street,
                    'postalCode' => $postalCode,
                    'city' => $city,
                    'lat' => null,
                    'lon' => null,
                ],
            ];
        }
        return [
            'valid' => false,
            'message' => 'Adresse introuvable - veuillez vérifier et réessayer',
            'source' => null,
            'normalized' => null,
        ];
    }

    private function normalizeStreetForSearch(string $street, string $postalCode, string $city): string
    {
        $value = mb_strtolower($street);
        $value = str_replace(mb_strtolower($postalCode), ' ', $value);
        $value = str_replace(mb_strtolower($city), ' ', $value);
        // Remove standalone numbers
        $value = preg_replace('/\b\d+\b/u', ' ', $value);
        // Normalize spaces
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    private function normalizeCountry(string $country): string
    {
        $normalized = strtoupper(trim($country));
        if ($normalized === 'FRANCE') {
            return 'FR';
        }

        return $normalized !== '' ? $normalized : 'FR';
    }
}
