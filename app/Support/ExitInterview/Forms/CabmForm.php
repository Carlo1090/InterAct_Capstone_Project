<?php

namespace App\Support\ExitInterview\Forms;

use App\Support\ExitInterview\ExitInterviewForm;

/**
 * The CABM "Internship Program Student Exit Interview Form" — the original
 * form, and the template every other department's form is laid onto.
 *
 * Reference: docs/reference/INTERNSHIP PROGRAM STUDENT EXIT INTERVIEW - BUSINESS.pdf
 *
 * Content is verbatim from the reference — the wording, the numbering and the
 * section order are the college's, not ours. Sections start at B because the
 * template's Section A is the student information block.
 */
final class CabmForm
{
    public static function definition(): ExitInterviewForm
    {
        return new ExitInterviewForm(
            key: 'cabm',
            label: 'Internship Program Student Exit Interview Form (CABM)',
            collegeLine: 'College of Accountancy, Business and Management (CABM)',
            titleLines: ['INTERNSHIP PROGRAM STUDENT EXIT', 'INTERVIEW FORM'],
            preamble: [
                'This exit interview gathers feedback from students regarding their OJT/Internship experience. The information',
                'collected will help evaluate the effectiveness of the Internship program and identify areas for improvement.',
            ],
            studentInfoHeading: 'A. Student Information',
            reference: 'INTERNSHIP PROGRAM STUDENT EXIT INTERVIEW - BUSINESS.pdf',
            sections: [
                [
                    'heading' => 'B. Internship Placement and Responsibilities',
                    'questions' => [
                        ['n' => 1, 'key' => 'q1', 'text' => 'What were your primary duties and responsibilities during your internship?'],
                        ['n' => 2, 'key' => 'q2', 'text' => 'Were your assigned tasks relevant to your academic program?', 'choice' => 'q2_choice', 'label' => 'Please explain:'],
                    ],
                ],
                [
                    'heading' => 'C. Skills and Competencies Developed',
                    'questions' => [
                        ['n' => 3, 'key' => 'q3', 'text' => 'What technical skills did you learn or improve during your internship?'],
                        ['n' => 4, 'key' => 'q4', 'text' => 'What soft skills did you develop during your internship? (e.g., communication, teamwork, time management, professionalism)'],
                        ['n' => 5, 'key' => 'q5', 'text' => 'Which skill do you think improved the most during your training?'],
                    ],
                ],
                [
                    'heading' => 'D. Internship Experience',
                    'questions' => [
                        ['n' => 6, 'key' => 'q6', 'text' => 'How would you describe your overall internship experience?'],
                        ['n' => 7, 'key' => 'q7', 'text' => 'Were you given adequate supervision and guidance by your company supervisor?', 'choice' => 'q7_choice', 'label' => 'Please explain:'],
                    ],
                ],
                [
                    'heading' => 'E. Challenges Encountered',
                    'questions' => [
                        ['n' => 8, 'key' => 'q8', 'text' => 'What challenges did you encounter during your internship? How did you address these challenges?'],
                    ],
                ],
                [
                    'heading' => 'F. Learning and Career Insights',
                    'questions' => [
                        ['n' => 9, 'key' => 'q9', 'text' => 'What important lessons did you learn from your internship?'],
                        ['n' => 10, 'key' => 'q10', 'text' => 'Did your internship influence your career plans?', 'choice' => 'q10_choice', 'label' => 'If yes, please explain:'],
                        ['n' => 11, 'key' => 'q11', 'text' => 'Do you feel prepared to enter the workforce after completing your OJT/INTERNSHIP?', 'choice' => 'q11_choice'],
                    ],
                ],
                [
                    'heading' => 'G. Feedback and Recommendations',
                    'questions' => [
                        ['n' => 12, 'key' => 'q12', 'text' => 'What aspects of the OJT/INTERNSHIP program were most beneficial to you?'],
                        ['n' => 13, 'key' => 'q13', 'text' => 'What improvements would you suggest for the OJT/INTERNSHIP program?'],
                        ['n' => 14, 'key' => 'q14', 'text' => 'What advice would you give to future OJT/INTERNSHIP students?'],
                    ],
                ],
            ],
        );
    }
}
