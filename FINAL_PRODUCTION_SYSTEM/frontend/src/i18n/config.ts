import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './en.json'
import ru from './ru.json'

// All available languages — dynamically imported when needed
const LAZY_IMPORTS: Record<string, () => Promise<{ default: Record<string, string> }>> = {
  ar: () => import('./ar.json'),
  tr: () => import('./tr.json'),
  zh: () => import('./zh.json'),
  es: () => import('./es.json'),
  pt: () => import('./pt.json'),
  de: () => import('./de.json'),
  fr: () => import('./fr.json'),
  ja: () => import('./ja.json'),
  ko: () => import('./ko.json'),
  it: () => import('./it.json'),
  pl: () => import('./pl.json'),
  nl: () => import('./nl.json'),
  uk: () => import('./uk.json'),
  hi: () => import('./hi.json'),
  id: () => import('./id.json'),
  vi: () => import('./vi.json'),
}

// RTL languages
export const RTL_LANGUAGES = new Set(['ar', 'he'])

// Language metadata for the switcher UI
export const LANGUAGE_META: Record<string, { name: string; nativeName: string; rtl: boolean }> = {
  en: { name: 'English', nativeName: 'English', rtl: false },
  ru: { name: 'Russian', nativeName: 'Русский', rtl: false },
  ar: { name: 'Arabic', nativeName: 'العربية', rtl: true },
  tr: { name: 'Turkish', nativeName: 'Türkçe', rtl: false },
  zh: { name: 'Chinese', nativeName: '简体中文', rtl: false },
  es: { name: 'Spanish', nativeName: 'Español', rtl: false },
  pt: { name: 'Portuguese', nativeName: 'Português', rtl: false },
  de: { name: 'German', nativeName: 'Deutsch', rtl: false },
  fr: { name: 'French', nativeName: 'Français', rtl: false },
  ja: { name: 'Japanese', nativeName: '日本語', rtl: false },
  ko: { name: 'Korean', nativeName: '한국어', rtl: false },
  it: { name: 'Italian', nativeName: 'Italiano', rtl: false },
  pl: { name: 'Polish', nativeName: 'Polski', rtl: false },
  nl: { name: 'Dutch', nativeName: 'Nederlands', rtl: false },
  uk: { name: 'Ukrainian', nativeName: 'Українська', rtl: false },
  hi: { name: 'Hindi', nativeName: 'हिन्दी', rtl: false },
  id: { name: 'Indonesian', nativeName: 'Bahasa Indonesia', rtl: false },
  vi: { name: 'Vietnamese', nativeName: 'Tiếng Việt', rtl: false },
}

const savedLang = localStorage.getItem('oem-ui-lang') || 'en'

i18n.use(initReactI18next).init({
  resources: {
    en: { translation: en },
    ru: { translation: ru },
  },
  lng: savedLang,
  fallbackLng: 'en',
  keySeparator: false,
  nsSeparator: false,
  interpolation: {
    escapeValue: false,
  },
})

/**
 * Dynamically load a language bundle if not already loaded.
 * Called when user switches language in the sidebar.
 */
export async function loadLanguageBundle(code: string): Promise<void> {
  // Already loaded (en/ru are bundled, others may have been loaded before)
  if (i18n.hasResourceBundle(code, 'translation')) {
    return
  }

  const loader = LAZY_IMPORTS[code]
  if (!loader) {
    console.warn(`No translation file for language: ${code}`)
    return
  }

  try {
    const module = await loader()
    i18n.addResourceBundle(code, 'translation', module.default, true, true)
  } catch (e) {
    console.error(`Failed to load language ${code}:`, e)
  }
}

/**
 * Switch to a language, loading its bundle first if needed.
 * Also sets document direction for RTL languages.
 */
export async function switchLanguage(code: string): Promise<void> {
  await loadLanguageBundle(code)
  await i18n.changeLanguage(code)
  localStorage.setItem('oem-ui-lang', code)

  // Set document direction for RTL support
  document.documentElement.dir = RTL_LANGUAGES.has(code) ? 'rtl' : 'ltr'
  document.documentElement.lang = code
}

// Set initial direction
if (RTL_LANGUAGES.has(savedLang)) {
  document.documentElement.dir = 'rtl'
}
document.documentElement.lang = savedLang

// Load saved language bundle on startup (if not en/ru)
if (savedLang !== 'en' && savedLang !== 'ru') {
  loadLanguageBundle(savedLang)
}

export default i18n
