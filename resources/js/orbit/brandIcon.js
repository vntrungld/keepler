// Brand-icon lookup for the subscription list/detail UI.
//
// Which brand gets which icon is declared in `resources/data/services.json`
// (the `icon` field, holding a simple-icons export name). This file only
// bridges those names to the actual icon objects: the imports below are
// static and explicit so the bundler ships exactly the 42 icons the catalog
// asks for, rather than pulling in all ~3,400 of them.
//
// NOTE: simple-icons@16 does not ship icons for Adobe, Amazon, Disney+,
// Microsoft, OpenAI, Canva, Hulu, Xbox, Nintendo, Peacock and a handful more
// (pulled for trademark reasons). Those catalog entries carry `icon: null`;
// resolveBrandIcon() returns null for them and BrandIcon.vue falls back to
// the domain favicon, then to the letter avatar.
import {
    si1password,
    siApple,
    siApplearcade,
    siApplemusic,
    siAppletv,
    siAudible,
    siClaude,
    siCrunchyroll,
    siCursor,
    siDoordash,
    siDropbox,
    siDuolingo,
    siEvernote,
    siFitbit,
    siGithubcopilot,
    siGoogle,
    siGooglegemini,
    siGrammarly,
    siHeadspace,
    siIcloud,
    siMax,
    siMedium,
    siNetflix,
    siNordvpn,
    siNotion,
    siParamountplus,
    siPeloton,
    siPerplexity,
    siPlaystation,
    siProtonmail,
    siProtonvpn,
    siShopify,
    siSpotify,
    siSquarespace,
    siStrava,
    siThewashingtonpost,
    siTodoist,
    siTradingview,
    siUber,
    siYoutube,
    siYoutubemusic,
    siYoutubetv,
} from 'simple-icons';
import { resolveService } from './brandColors.js';

const ICONS = {
    si1password,
    siApple,
    siApplearcade,
    siApplemusic,
    siAppletv,
    siAudible,
    siClaude,
    siCrunchyroll,
    siCursor,
    siDoordash,
    siDropbox,
    siDuolingo,
    siEvernote,
    siFitbit,
    siGithubcopilot,
    siGoogle,
    siGooglegemini,
    siGrammarly,
    siHeadspace,
    siIcloud,
    siMax,
    siMedium,
    siNetflix,
    siNordvpn,
    siNotion,
    siParamountplus,
    siPeloton,
    siPerplexity,
    siPlaystation,
    siProtonmail,
    siProtonvpn,
    siShopify,
    siSpotify,
    siSquarespace,
    siStrava,
    siThewashingtonpost,
    siTodoist,
    siTradingview,
    siUber,
    siYoutube,
    siYoutubemusic,
    siYoutubetv,
};

export function resolveBrandIcon(name) {
    const icon = ICONS[resolveService(name)?.icon];
    if (!icon) return null;

    return { path: icon.path, hex: icon.hex, title: icon.title };
}
