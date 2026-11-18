export interface ValidationEvidenceOptions {
  manifest?: string;
  proves?: string[];
  issuer?: string;
  record?: boolean;
  reuse?: string[];
}
export function withValidationEvidence<T>(
  options: ValidationEvidenceOptions,
  execute: (context: {
    env: NodeJS.ProcessEnv;
    reused: boolean;
  }) => T | Promise<T>,
): Promise<T>;
export function reuseValidation(command: string[], manifest?: string): boolean;
export function executionProofs(command: string[], manifest?: string): string[];

export function matchingProof(
  command: string[],
  manifest?: string,
): string | undefined;
