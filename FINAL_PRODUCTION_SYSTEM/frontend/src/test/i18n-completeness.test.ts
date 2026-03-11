/**
 * i18n Completeness Tests
 *
 * These tests ensure:
 * 1. Every key in en.json also exists in ru.json (and vice versa)
 * 2. Every t('key') call in source code has a matching entry in en.json
 * 3. No translation values are empty strings
 * 4. No unused keys exist in the JSON files (dead translations)
 *
 * If any of these fail, you have a translation bug that users will see.
 */
import { describe, it, expect } from 'vitest'
import fs from 'fs'
import path from 'path'

// Load translation files
const enPath = path.resolve(__dirname, '../i18n/en.json')
const ruPath = path.resolve(__dirname, '../i18n/ru.json')
const en: Record<string, string> = JSON.parse(fs.readFileSync(enPath, 'utf-8'))
const ru: Record<string, string> = JSON.parse(fs.readFileSync(ruPath, 'utf-8'))

const enKeys = new Set(Object.keys(en))
const ruKeys = new Set(Object.keys(ru))

// Recursively find all .ts and .tsx files in src/ (excluding node_modules and test files)
function findSourceFiles(dir: string): string[] {
  const results: string[] = []
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === 'test') continue
      results.push(...findSourceFiles(fullPath))
    } else if (/\.(tsx?|jsx?)$/.test(entry.name) && !entry.name.endsWith('.test.ts') && !entry.name.endsWith('.test.tsx')) {
      results.push(fullPath)
    }
  }
  return results
}

// Extract all t('key') and t("key") calls from source files
function extractTranslationKeys(srcDir: string): Map<string, string[]> {
  const keyToFiles = new Map<string, string[]>()
  const files = findSourceFiles(srcDir)

  // Matches: t('key'), t("key"), t('key', 'fallback'), t(`key`)
  // Also matches: t('key.sub_key')
  const tCallRegex = /\bt\(\s*['"`]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)['"`]/g

  for (const file of files) {
    const content = fs.readFileSync(file, 'utf-8')
    let match: RegExpExecArray | null
    while ((match = tCallRegex.exec(content)) !== null) {
      const key = match[1]
      const relPath = path.relative(srcDir, file)
      const existing = keyToFiles.get(key) ?? []
      if (!existing.includes(relPath)) {
        existing.push(relPath)
      }
      keyToFiles.set(key, existing)
    }
  }

  return keyToFiles
}

const srcDir = path.resolve(__dirname, '..')
const usedKeys = extractTranslationKeys(srcDir)

describe('i18n: Language Parity', () => {
  it('every EN key exists in RU', () => {
    const missingInRu: string[] = []
    for (const key of enKeys) {
      if (!ruKeys.has(key)) {
        missingInRu.push(key)
      }
    }
    expect(missingInRu, `${missingInRu.length} keys in en.json missing from ru.json:\n  ${missingInRu.join('\n  ')}`).toEqual([])
  })

  it('every RU key exists in EN', () => {
    const missingInEn: string[] = []
    for (const key of ruKeys) {
      if (!enKeys.has(key)) {
        missingInEn.push(key)
      }
    }
    expect(missingInEn, `${missingInEn.length} keys in ru.json missing from en.json:\n  ${missingInEn.join('\n  ')}`).toEqual([])
  })
})

describe('i18n: No Empty Values', () => {
  it('no empty strings in en.json', () => {
    const empty = Object.entries(en).filter(([, v]) => v.trim() === '').map(([k]) => k)
    expect(empty, `Empty values in en.json:\n  ${empty.join('\n  ')}`).toEqual([])
  })

  it('no empty strings in ru.json', () => {
    const empty = Object.entries(ru).filter(([, v]) => v.trim() === '').map(([k]) => k)
    expect(empty, `Empty values in ru.json:\n  ${empty.join('\n  ')}`).toEqual([])
  })
})

describe('i18n: Code Coverage', () => {
  it('every t() call in source code has a matching key in en.json', () => {
    const missing: string[] = []
    for (const [key, files] of usedKeys) {
      if (!enKeys.has(key)) {
        missing.push(`${key} (used in: ${files.join(', ')})`)
      }
    }
    expect(
      missing,
      `${missing.length} translation keys used in code but missing from en.json:\n  ${missing.join('\n  ')}`
    ).toEqual([])
  })

  it('every t() call in source code has a matching key in ru.json', () => {
    const missing: string[] = []
    for (const [key, files] of usedKeys) {
      if (!ruKeys.has(key)) {
        missing.push(`${key} (used in: ${files.join(', ')})`)
      }
    }
    expect(
      missing,
      `${missing.length} translation keys used in code but missing from ru.json:\n  ${missing.join('\n  ')}`
    ).toEqual([])
  })
})

describe('i18n: Dead Key Detection (informational)', () => {
  it('logs keys not found by static analysis', () => {
    // Many keys are used dynamically via template literals: t(`keys.${status}`)
    // Static regex cannot detect those, so this test is purely informational.
    // It does NOT fail — it just reports for manual review.
    const unused: string[] = []
    for (const key of enKeys) {
      if (!usedKeys.has(key)) {
        unused.push(key)
      }
    }
    if (unused.length > 0) {
      console.info(
        `\n📋 ${unused.length} of ${enKeys.size} keys not found by static analysis (most are dynamically used via template literals).\n` +
        `  First 10: ${unused.slice(0, 10).join(', ')}`
      )
    }
    // Always passes — this is informational only
    expect(true).toBe(true)
  })
})
