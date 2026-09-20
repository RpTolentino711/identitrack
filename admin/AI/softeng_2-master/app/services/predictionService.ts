import { ViolationPrediction } from '../types';

const API_URL = process.env.NEXT_PUBLIC_PREDICTION_API_URL || 'http://localhost:5000/predict';

export const getViolationPrediction = async (
  description: string,
  category: string,
  violation: string,
  number_of_offense: string
): Promise<ViolationPrediction> => {
  try {
    const response = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ description, category, violation, number_of_offense }),
    });

    if (!response.ok) {
      throw new Error('Network response was not ok');
    }

    const data = await response.json();
    // Normalize Flask response to ViolationPrediction
    const confidence =
      typeof data.confidence_score === 'number'
        ? data.confidence_score
        : typeof data.likelihood_percentage === 'number'
          ? data.likelihood_percentage
          : 0;

    return {
      category: data.category ?? 'Uncategorized',
      severity: data.severity ?? 'Low',
      confidence_level: confidence,
      sanction: data.sanction ?? undefined,
      sanction_confidence: typeof data.sanction_confidence === 'number' ? data.sanction_confidence : undefined,
    } as ViolationPrediction;
  } catch (error) {
    console.error('Failed to get violation prediction:', error);
    return {
      category: 'Uncategorized',
      severity: 'Low',
      confidence_level: 10,
    };
  }
};
