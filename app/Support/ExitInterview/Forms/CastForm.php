<?php

namespace App\Support\ExitInterview\Forms;

use App\Support\ExitInterview\ExitInterviewForm;

/**
 * The CAST "Exit Interview Questionnaire for On-the-Job (OJT) Students".
 *
 * Reference: docs/reference/EXIT INTERVIEW OJT CAST DEPARTMENT.pdf
 *
 * Laid onto the CABM template at the project owner's direction: the same
 * student information block, the same draft → submitted → reviewed flow, the
 * same coordinator block and signatories, the same page geometry. ONLY the
 * questions and the masthead are this department's own.
 *
 * VERIFIED AGAINST THE REFERENCE'S OWN TEXT (2026-09-15): every section
 * heading, every question's number and wording, the section membership, the
 * five rating options and the masthead lines were extracted from the PDF's
 * content stream and compared string-for-string — zero differences. What
 * deliberately differs is the TEMPLATE, not the transcription: the paper is
 * US Letter (612 x 792) with no ruled answer lines, lists Year Level under
 * Student Information, and closes with an attestation line plus
 * "Interviewed by / Reviewed by" signatories; the app prints the CABM
 * template's long-bond page, five rules per answer, Section A fields and
 * coordinator block instead, as directed.
 *
 * Three things about the paper form are carried over as-is rather than tidied:
 *
 *   - The numbering has GAPS — 1-22, then 28-33, then 35-37; there is no 23-27
 *     and no 34 on the printed page. The keys and printed numbers keep the
 *     paper's own numbering so the app and the sheet in a coordinator's hand
 *     agree. Renumbering is a data edit here (change `n`; keep `key`) once the
 *     department confirms which it wants.
 *   - Question 33 is a five-point rating (☐ Excellent … ☐ Poor) with no
 *     answer lines, the one question on either form that is not free text.
 *   - The paper's Section A is "OJT Experience", so the template's student
 *     information block is headed "Student Information" without a letter,
 *     exactly as the CAST page prints it.
 */
final class CastForm
{
    public static function definition(): ExitInterviewForm
    {
        return new ExitInterviewForm(
            key: 'cast',
            label: 'Exit Interview Questionnaire for On-the-Job (OJT) Students (CAST)',
            collegeLine: 'College of Arts, Sciences, and Technology (CAST)',
            // The paper sets its title on ONE line (318.6pt at the masthead's
            // 9.4pt, well inside the page) with a subtitle beneath it; both are
            // reproduced in the template's two title slots. Verified against
            // the reference's own text, 2026-09-15.
            titleLines: ['EXIT INTERVIEW QUESTIONNAIRE FOR ON-THE-JOB (OJT) STUDENTS'],
            subtitle: 'On-the-Job Training (OJT) Program',
            preamble: [],
            studentInfoHeading: 'Student Information',
            reference: 'EXIT INTERVIEW OJT CAST DEPARTMENT.pdf',
            sections: [
                [
                    'heading' => 'A. OJT Experience',
                    'questions' => [
                        ['n' => 1, 'key' => 'q1', 'text' => 'How would you describe your overall experience during your OJT?'],
                        ['n' => 2, 'key' => 'q2', 'text' => 'What were the most valuable experiences you gained during your OJT?'],
                        ['n' => 3, 'key' => 'q3', 'text' => 'What specific knowledge or skills did you learn or improve during your OJT?'],
                        ['n' => 4, 'key' => 'q4', 'text' => 'Which tasks or activities did you find most meaningful? Why?'],
                        ['n' => 5, 'key' => 'q5', 'text' => 'Were the tasks assigned to you related to your course or field of specialization? Please explain.'],
                        ['n' => 6, 'key' => 'q6', 'text' => 'What challenges or difficulties did you encounter during your OJT?'],
                        ['n' => 7, 'key' => 'q7', 'text' => 'How did you deal with or overcome these challenges?'],
                    ],
                ],
                [
                    'heading' => 'B. Workplace and Training Environment',
                    'questions' => [
                        ['n' => 8, 'key' => 'q8', 'text' => 'How would you describe the working environment in your assigned company/agency?'],
                        ['n' => 9, 'key' => 'q9', 'text' => 'Were you given sufficient orientation before starting your OJT?'],
                        ['n' => 10, 'key' => 'q10', 'text' => 'Were the facilities, equipment, and resources adequate for your training?'],
                        ['n' => 11, 'key' => 'q11', 'text' => 'How would you describe your relationship with your immediate supervisor or training officer?'],
                        ['n' => 12, 'key' => 'q12', 'text' => 'Did your supervisor provide adequate guidance, feedback, and support? Please explain.'],
                        ['n' => 13, 'key' => 'q13', 'text' => 'Were you treated fairly and respectfully as an OJT student?'],
                    ],
                ],
                [
                    'heading' => 'C. Application of Knowledge and Skills',
                    'questions' => [
                        ['n' => 14, 'key' => 'q14', 'text' => 'Were you able to apply the knowledge and skills you learned in school during your OJT?'],
                        ['n' => 15, 'key' => 'q15', 'text' => 'What academic knowledge or skills were most useful during your training?'],
                        ['n' => 16, 'key' => 'q16', 'text' => 'What skills did you realize you still need to improve?'],
                        ['n' => 17, 'key' => 'q17', 'text' => 'Did your OJT help you become more confident in performing work-related tasks? Why or why not?'],
                        ['n' => 18, 'key' => 'q18', 'text' => 'Did the OJT help you better understand the expectations and responsibilities of your future profession?'],
                    ],
                ],
                [
                    'heading' => 'D. Professional Development',
                    'questions' => [
                        ['n' => 19, 'key' => 'q19', 'text' => 'What professional values or attitudes did you develop during your OJT?'],
                        ['n' => 20, 'key' => 'q20', 'text' => 'Did your OJT improve your communication, teamwork, time management, or problem-solving skills? Please give examples.'],
                        ['n' => 21, 'key' => 'q21', 'text' => 'What did you learn about workplace discipline, professionalism, and responsibility?'],
                        ['n' => 22, 'key' => 'q22', 'text' => 'Did the OJT experience influence your career plans or professional goals? How?'],
                    ],
                ],
                [
                    'heading' => 'E. Overall Assessment',
                    'questions' => [
                        ['n' => 28, 'key' => 'q28', 'text' => 'What was the best aspect of your OJT experience?'],
                        ['n' => 29, 'key' => 'q29', 'text' => 'What was the most difficult or least satisfactory aspect of your OJT experience?'],
                        ['n' => 30, 'key' => 'q30', 'text' => 'What changes or improvements would you recommend for future OJT students?'],
                        ['n' => 31, 'key' => 'q31', 'text' => 'What advice would you give to students who will undergo OJT in the future?'],
                        ['n' => 32, 'key' => 'q32', 'text' => 'Would you recommend your OJT company/agency to future students? Why or why not?'],
                        [
                            'n' => 33,
                            'key' => 'q33',
                            'text' => 'Overall, how would you rate your OJT experience?',
                            'type' => ExitInterviewForm::TYPE_SCALE,
                            'options' => [
                                'excellent' => 'Excellent',
                                'very_good' => 'Very Good',
                                'good' => 'Good',
                                'fair' => 'Fair',
                                'poor' => 'Poor',
                            ],
                        ],
                    ],
                ],
                [
                    'heading' => 'F. Final Reflection',
                    'questions' => [
                        ['n' => 35, 'key' => 'q35', 'text' => 'What is the most important lesson you learned from your OJT experience?'],
                        ['n' => 36, 'key' => 'q36', 'text' => 'What skills or competencies do you plan to further develop after completing your OJT?'],
                        ['n' => 37, 'key' => 'q37', 'text' => 'Is there anything else you would like to share about your OJT experience that was not covered by the questions above?'],
                    ],
                ],
            ],
        );
    }
}
