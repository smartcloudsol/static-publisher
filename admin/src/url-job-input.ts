export function parseUrlJobLines(value: string): string[] {
  return [...new Set(value.split(/\r?\n/).map(line => line.trim()).filter(Boolean))];
}

export function urlJobFields(value: string): { urls: string[] } {
  return { urls: parseUrlJobLines(value) };
}
