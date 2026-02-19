<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    // Scoring weights
    private const W_TITLE_EXACT   = 100;
    private const W_CATEGORY      = 80;
    private const W_SKILLS        = 60;
    private const W_BIO           = 50;
    private const W_FUZZY         = 20;

    // Common misspellings map (Chilean Spanish)
    private const SYNONYMS = [
        'gafiter'      => 'gasfíter',
        'gafiter'      => 'gasfiter',
        'elecricista'  => 'electricista',
        'electrisista' => 'electricista',
        'pintol'       => 'pintor',
        'plomero'      => 'gasfíter',
        'fontanero'    => 'gasfíter',
        'mecanico'     => 'mecánico',
        'albañil'      => 'albañil',
        'albanil'      => 'albañil',
        'jardinero'    => 'jardinero',
        'cerrajero'    => 'cerrajero',
        'serajero'     => 'cerrajero',
        'limpiesa'     => 'limpieza',
    ];

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q'      => 'required|string|min:1|max:100',
            'lat'    => 'nullable|numeric|between:-90,90',
            'lng'    => 'nullable|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:0.1|max:100',
        ]);

        $rawQuery = trim($validated['q']);
        $lat = $validated['lat'] ?? null;
        $lng = $validated['lng'] ?? null;
        $radius = $validated['radius'] ?? 50; // default 50km for search

        // 1. Tokenize input
        $tokens = $this->tokenize($rawQuery);

        // 2. Expand tokens with synonyms/corrections
        $expandedTokens = $this->expandTokens($tokens);

        // 3. Search categories first (fast path)
        $matchingCategories = $this->searchCategories($tokens, $expandedTokens);
        $matchingCategoryIds = $matchingCategories->pluck('id')->toArray();

        // 4. Build worker query
        $query = Worker::whereIn('availability_status', ['active', 'intermediate'])
            ->with(['user:id,name,nickname,avatar', 'category:id,slug,display_name,color,icon'])
            ->withCount('videos');

        if ($lat && $lng) {
            $query->near($lat, $lng, $radius);
            $query->selectRaw('workers.*, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng');
        } else {
            $query->select('workers.*');
        }

        $workers = $query->get();

        // 5. Score each worker
        $scored = $workers->map(function ($w) use ($tokens, $expandedTokens, $matchingCategoryIds, $rawQuery) {
            $score = 0;
            $matches = [];

            $title = mb_strtolower($w->title ?? '');
            $bio = mb_strtolower($w->bio ?? '');
            $skills = is_array($w->skills) ? array_map('mb_strtolower', $w->skills) : [];
            $categoryName = mb_strtolower($w->category?->display_name ?? '');
            $userName = mb_strtolower($w->user?->name ?? '');

            // Exact full query match in title
            if ($title && str_contains($title, mb_strtolower($rawQuery))) {
                $score += self::W_TITLE_EXACT;
                $matches[] = 'title_exact';
            }

            // Category match
            if (in_array($w->category_id, $matchingCategoryIds)) {
                $score += self::W_CATEGORY;
                $matches[] = 'category';
            }

            foreach ($tokens as $token) {
                $lower = mb_strtolower($token);

                // Title partial
                if ($title && str_contains($title, $lower) && !in_array('title_exact', $matches)) {
                    $score += self::W_TITLE_EXACT * 0.7;
                    $matches[] = 'title_partial';
                }

                // Skills
                foreach ($skills as $skill) {
                    if (str_contains($skill, $lower)) {
                        $score += self::W_SKILLS;
                        $matches[] = 'skill';
                        break;
                    }
                }

                // Bio
                if ($bio && str_contains($bio, $lower)) {
                    $score += self::W_BIO;
                    $matches[] = 'bio';
                }

                // Category name direct
                if ($categoryName && str_contains($categoryName, $lower)) {
                    $score += self::W_CATEGORY * 0.5;
                    $matches[] = 'category_name';
                }

                // Name match
                if ($userName && str_contains($userName, $lower)) {
                    $score += 40;
                    $matches[] = 'name';
                }
            }

            // Fuzzy matching on expanded tokens
            foreach ($expandedTokens as $expanded) {
                if (in_array($expanded, $tokens)) continue; // skip originals
                $lower = mb_strtolower($expanded);

                if ($title && str_contains($title, $lower)) {
                    $score += self::W_FUZZY;
                    $matches[] = 'fuzzy_title';
                }
                if ($categoryName && str_contains($categoryName, $lower)) {
                    $score += self::W_FUZZY;
                    $matches[] = 'fuzzy_category';
                }
                foreach ($skills as $skill) {
                    if (str_contains($skill, $lower)) {
                        $score += self::W_FUZZY;
                        $matches[] = 'fuzzy_skill';
                        break;
                    }
                }
            }

            // Levenshtein fuzzy on category names (catch typos)
            if ($score === 0) {
                $allCategories = Category::active()->pluck('display_name')->toArray();
                foreach ($tokens as $token) {
                    foreach ($allCategories as $catName) {
                        $distance = levenshtein(mb_strtolower($token), mb_strtolower($catName));
                        $maxLen = max(mb_strlen($token), mb_strlen($catName));
                        if ($maxLen > 0 && ($distance / $maxLen) < 0.35) {
                            if ($categoryName && str_contains(mb_strtolower($catName), mb_strtolower($w->category?->display_name ?? '---'))) {
                                $score += self::W_FUZZY;
                                $matches[] = 'levenshtein_category';
                            }
                        }
                    }
                }
            }

            // Levenshtein on individual tokens vs title words
            if ($score === 0 && $title) {
                $titleWords = preg_split('/\s+/', $title);
                foreach ($tokens as $token) {
                    foreach ($titleWords as $tw) {
                        $distance = levenshtein(mb_strtolower($token), $tw);
                        $maxLen = max(mb_strlen($token), mb_strlen($tw));
                        if ($maxLen > 0 && ($distance / $maxLen) < 0.3) {
                            $score += self::W_FUZZY;
                            $matches[] = 'levenshtein_title';
                            break 2;
                        }
                    }
                }
            }

            $w->search_score = $score;
            $w->search_matches = array_unique($matches);
            return $w;
        })
        ->filter(fn($w) => $w->search_score > 0)
        ->sortByDesc('search_score')
        ->values();

        return response()->json([
            'status' => 'success',
            'meta' => [
                'query' => $rawQuery,
                'tokens' => $tokens,
                'expanded' => $expandedTokens,
                'total_found' => $scored->count(),
                'matching_categories' => $matchingCategories->pluck('display_name'),
            ],
            'data' => $scored->map(fn($w) => [
                'id' => $w->id,
                'pos' => $lat ? [
                    'lat' => ($w->lat ?? 0) + (mt_rand(-10, 10) * 0.0001),
                    'lng' => ($w->lng ?? 0) + (mt_rand(-10, 10) * 0.0001),
                ] : null,
                'name' => $w->user->nickname ?? $this->shortName($w->user->name ?? ''),
                'full_name' => $w->isActive() ? $w->user->name : null,
                'avatar' => $w->user->avatar,
                'title' => $w->title,
                'price' => (int) $w->hourly_rate,
                'category_color' => $w->category?->color ?? '#2563eb',
                'category_slug' => $w->category?->slug,
                'category_name' => $w->category?->display_name,
                'category_icon' => $w->category?->icon,
                'fresh_score' => (float) $w->fresh_score,
                'status' => $w->availability_status,
                'has_video' => $w->videos_count > 0,
                'search_score' => $w->search_score,
                'search_matches' => $w->search_matches,
            ])->take(30),
        ]);
    }

    private function tokenize(string $input): array
    {
        // Remove accents for matching but keep originals too
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $input);
        $words = preg_split('/\s+/', trim($cleaned));
        return array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2));
    }

    private function expandTokens(array $tokens): array
    {
        $expanded = $tokens;
        foreach ($tokens as $token) {
            $lower = mb_strtolower($token);
            // Check synonym map
            if (isset(self::SYNONYMS[$lower])) {
                $expanded[] = self::SYNONYMS[$lower];
            }
            // Strip accents and add as variant
            $noAccent = $this->stripAccents($lower);
            if ($noAccent !== $lower) {
                $expanded[] = $noAccent;
            }
        }
        return array_values(array_unique($expanded));
    }

    private function searchCategories(array $tokens, array $expandedTokens): \Illuminate\Support\Collection
    {
        $categories = Category::active()->get();
        $allTokens = array_merge($tokens, $expandedTokens);

        return $categories->filter(function ($cat) use ($allTokens) {
            $name = mb_strtolower($cat->display_name);
            $slug = mb_strtolower($cat->slug);
            foreach ($allTokens as $token) {
                $lower = mb_strtolower($token);
                if (str_contains($name, $lower) || str_contains($slug, $lower)) {
                    return true;
                }
                // Levenshtein on category name
                $distance = levenshtein($lower, $name);
                $maxLen = max(mb_strlen($lower), mb_strlen($name));
                if ($maxLen > 0 && ($distance / $maxLen) < 0.3) {
                    return true;
                }
            }
            return false;
        });
    }

    private function stripAccents(string $str): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ñ' => 'N', 'Ü' => 'U',
        ];
        return strtr($str, $map);
    }

    private function shortName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        if (count($parts) <= 1) return $fullName;
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
    }
}
