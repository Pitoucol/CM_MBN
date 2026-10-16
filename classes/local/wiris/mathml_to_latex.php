<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace report_coursemanager\local\wiris;

/**
 * Converts one Presentation MathML expression (as produced by Wiris/MathType) into LaTeX.
 *
 * Pure PHP (DOM extension only), no dependency on Moodle, so it can be unit tested alone.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mathml_to_latex {

    /** @var array Unicode character => LaTeX (math mode). */
    const SYMBOLS = [
        // Greek, lower case.
        'α' => '\alpha', 'β' => '\beta', 'γ' => '\gamma', 'δ' => '\delta', 'ε' => '\varepsilon', 'ϵ' => '\epsilon',
        'ζ' => '\zeta', 'η' => '\eta', 'θ' => '\theta', 'ϑ' => '\vartheta', 'ι' => '\iota', 'κ' => '\kappa',
        'λ' => '\lambda', 'μ' => '\mu', 'µ' => '\mu', 'ν' => '\nu', 'ξ' => '\xi', 'ο' => 'o', 'π' => '\pi',
        'ϖ' => '\varpi', 'ρ' => '\rho', 'ϱ' => '\varrho', 'σ' => '\sigma', 'ς' => '\varsigma', 'τ' => '\tau',
        'υ' => '\upsilon', 'φ' => '\varphi', 'ϕ' => '\phi', 'χ' => '\chi', 'ψ' => '\psi', 'ω' => '\omega',
        // Greek, upper case.
        'Γ' => '\Gamma', 'Δ' => '\Delta', 'Θ' => '\Theta', 'Λ' => '\Lambda', 'Ξ' => '\Xi', 'Π' => '\Pi',
        'Σ' => '\Sigma', 'Υ' => '\Upsilon', 'Φ' => '\Phi', 'Ψ' => '\Psi', 'Ω' => '\Omega', 'Ω' => '\Omega',
        'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M',
        'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Χ' => 'X',
        // Letter-like symbols.
        'ℝ' => '\mathbb{R}', 'ℕ' => '\mathbb{N}', 'ℤ' => '\mathbb{Z}', 'ℚ' => '\mathbb{Q}', 'ℂ' => '\mathbb{C}',
        'ℙ' => '\mathbb{P}', 'ℍ' => '\mathbb{H}', 'ⅇ' => 'e', 'ⅈ' => 'i', 'ⅆ' => 'd',
        'ℏ' => '\hbar', 'ℓ' => '\ell', 'ℜ' => '\Re', 'ℑ' => '\Im', 'ℵ' => '\aleph', '℘' => '\wp',
        '∞' => '\infty', '∂' => '\partial', '∇' => '\nabla', '∅' => '\emptyset', '⌀' => '\emptyset',
        'Å' => '\mathring{A}',
        // Binary operators.
        '±' => '\pm', '∓' => '\mp', '×' => '\times', '÷' => '\div', '·' => '\cdot', '⋅' => '\cdot',
        '∙' => '\cdot', '−' => '-', '∗' => '\ast', '∘' => '\circ', '⊕' => '\oplus', '⊗' => '\otimes',
        '⊖' => '\ominus', '⊙' => '\odot', '∪' => '\cup', '∩' => '\cap', '∖' => '\setminus', '∧' => '\wedge',
        '∨' => '\vee', '⋆' => '\star', '†' => '\dagger', '‡' => '\ddagger', '⊔' => '\sqcup', '⊓' => '\sqcap',
        '⋃' => '\bigcup', '⋂' => '\bigcap',
        // Relations.
        '≤' => '\leq', '≥' => '\geq', '⩽' => '\leqslant', '⩾' => '\geqslant', '≠' => '\neq', '≈' => '\approx',
        '≡' => '\equiv', '≅' => '\cong', '≃' => '\simeq', '∼' => '\sim', '≁' => '\nsim', '∝' => '\propto',
        '≪' => '\ll', '≫' => '\gg', '≺' => '\prec', '≻' => '\succ', '≐' => '\doteq', '≜' => '\triangleq',
        '∈' => '\in', '∉' => '\notin', '∋' => '\ni', '⊂' => '\subset', '⊃' => '\supset', '⊆' => '\subseteq',
        '⊇' => '\supseteq', '⊄' => '\not\subset', '⊈' => '\nsubseteq', '⊊' => '\subsetneq', '⊥' => '\perp',
        '∥' => '\parallel', '∦' => '\nparallel', '∣' => '\mid', '∤' => '\nmid', '⊢' => '\vdash', '⊨' => '\models',
        '≮' => '\nless', '≯' => '\ngtr', '≰' => '\nleq', '≱' => '\ngeq', '≲' => '\lesssim', '≳' => '\gtrsim',
        // Arrows.
        '→' => '\rightarrow', '←' => '\leftarrow', '↔' => '\leftrightarrow', '⇒' => '\Rightarrow',
        '⇐' => '\Leftarrow', '⇔' => '\Leftrightarrow', '⟶' => '\longrightarrow', '⟵' => '\longleftarrow',
        '⟷' => '\longleftrightarrow', '⟹' => '\Longrightarrow', '⟸' => '\Longleftarrow',
        '⟺' => '\Longleftrightarrow', '↦' => '\mapsto', '⟼' => '\longmapsto', '↑' => '\uparrow',
        '↓' => '\downarrow', '↕' => '\updownarrow', '⇑' => '\Uparrow', '⇓' => '\Downarrow', '↗' => '\nearrow',
        '↘' => '\searrow', '↙' => '\swarrow', '↖' => '\nwarrow', '⇌' => '\rightleftharpoons',
        '⇋' => '\leftrightharpoons', '⇄' => '\rightleftarrows', '⇆' => '\leftrightarrows', '↪' => '\hookrightarrow',
        '↩' => '\hookleftarrow', '⇀' => '\rightharpoonup', '↼' => '\leftharpoonup', '↠' => '\twoheadrightarrow',
        // Logic, sets.
        '∀' => '\forall', '∃' => '\exists', '∄' => '\nexists', '¬' => '\neg', '∴' => '\therefore',
        '∵' => '\because',
        // Big operators.
        '∑' => '\sum', '∏' => '\prod', '∐' => '\coprod', '∫' => '\int', '∬' => '\iint', '∭' => '\iiint',
        '∮' => '\oint', '∯' => '\oint', '⨁' => '\bigoplus', '⨂' => '\bigotimes',
        // Delimiters and misc.
        '⟨' => '\langle', '⟩' => '\rangle', '〈' => '\langle', '〉' => '\rangle', '⌊' => '\lfloor',
        '⌋' => '\rfloor', '⌈' => '\lceil', '⌉' => '\rceil', '‖' => '\|', '∠' => '\angle', '∡' => '\measuredangle',
        '△' => '\triangle', '▵' => '\triangle', '□' => '\square', '◊' => '\lozenge', '°' => '\circ',
        '′' => '\prime', '″' => '\prime\prime', '‴' => '\prime\prime\prime', '…' => '\ldots', '⋯' => '\cdots',
        '⋮' => '\vdots', '⋱' => '\ddots', '√' => '\surd', '∔' => '\dotplus', '♯' => '\sharp', '♭' => '\flat',
        '✓' => '\checkmark', '⌢' => '\frown', '⌣' => '\smile',
        // ASCII characters that are special in LaTeX.
        '{' => '\{', '}' => '\}', '%' => '\%', '#' => '\#', '&' => '\&', '$' => '\$', '_' => '\_',
        '\\' => '\backslash', '~' => '\sim', '^' => '\hat{}',
        // Invisible operators and spaces.
        "\u{2061}" => '', "\u{2062}" => '', "\u{2063}" => '', "\u{2064}" => '', "\u{200B}" => '',
        "\u{00A0}" => '\ ', "\u{2009}" => '\,', "\u{200A}" => '\,', "\u{2005}" => '\:', "\u{2004}" => '\;',
        "\u{2002}" => '\;', "\u{2003}" => '\quad', "\u{205F}" => '\:', "\u{2006}" => '\,', "\u{2008}" => '\,',
        "\u{2007}" => '\ ', "\u{202F}" => '\,',
    ];

    /** @var array Standard LaTeX function names. */
    const FUNCTIONS = ['sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'arcsin', 'arccos', 'arctan', 'sinh', 'cosh',
        'tanh', 'coth', 'log', 'ln', 'lg', 'exp', 'lim', 'limsup', 'liminf', 'max', 'min', 'sup', 'inf', 'det',
        'dim', 'ker', 'deg', 'gcd', 'arg', 'Pr', 'hom'];

    /** @var array Other function names (French notations...) rendered with \operatorname. */
    const OPERATORNAMES = ['sh', 'ch', 'th', 'argsh', 'argch', 'argth', 'cotan', 'cotg', 'tg', 'Arcsin', 'Arccos',
        'Arctan', 'arccotan', 'pgcd', 'ppcm', 'PGCD', 'PPCM', 'Card', 'card', 'Ker', 'Im', 'rg', 'rang', 'Vect',
        'vect', 'Id', 'id', 'tr', 'Tr', 'sgn', 'sign', 'Re', 'Arg', 'mod', 'erf', 'Var', 'Cov', 'E', 'ord',
        'grad', 'div', 'rot', 'curl', 'Log'];

    /** @var array Operators whose limits go below/above (munder/mover). */
    const LARGEOPS = ['∑', '∏', '∐', '⋃', '⋂', '⨁', '⨂', 'lim', 'limsup', 'liminf', 'max', 'min', 'sup', 'inf',
        'det', 'gcd', 'Pr'];

    /** @var array Integral signs. */
    const INTEGRALS = ['∫', '∬', '∭', '∮', '∯'];

    /** @var array Accent character => [narrow command, wide command]. */
    const OVERACCENTS = [
        '→' => ['\vec', '\overrightarrow'], "\u{20D7}" => ['\vec', '\overrightarrow'],
        '⟶' => ['\vec', '\overrightarrow'], '←' => ['\overleftarrow', '\overleftarrow'],
        '↔' => ['\overleftrightarrow', '\overleftrightarrow'],
        '¯' => ['\bar', '\overline'], '‾' => ['\overline', '\overline'], '_' => ['\bar', '\overline'],
        "\u{0305}" => ['\bar', '\overline'], '―' => ['\overline', '\overline'], '−' => ['\bar', '\overline'],
        '-' => ['\bar', '\overline'], '^' => ['\hat', '\widehat'], 'ˆ' => ['\hat', '\widehat'],
        "\u{0302}" => ['\hat', '\widehat'], '~' => ['\tilde', '\widetilde'], '˜' => ['\tilde', '\widetilde'],
        "\u{0303}" => ['\tilde', '\widetilde'], '˙' => ['\dot', '\dot'], '.' => ['\dot', '\dot'],
        "\u{0307}" => ['\dot', '\dot'], '¨' => ['\ddot', '\ddot'], "\u{0308}" => ['\ddot', '\ddot'],
        'ˇ' => ['\check', '\check'], "\u{030C}" => ['\check', '\check'], '˘' => ['\breve', '\breve'],
        '´' => ['\acute', '\acute'], '`' => ['\grave', '\grave'], '˚' => ['\mathring', '\mathring'],
        '⏞' => ['\overbrace', '\overbrace'], '︷' => ['\overbrace', '\overbrace'],
        '⌒' => ['\overset{\frown}', '\overset{\frown}'], '⏜' => ['\overset{\frown}', '\overset{\frown}'],
        '⁀' => ['\overset{\frown}', '\overset{\frown}'],
    ];

    /** @var array Accent character => command, below the base. */
    const UNDERACCENTS = [
        '_' => '\underline', '‾' => '\underline', '¯' => '\underline', "\u{0332}" => '\underline',
        '−' => '\underline', '-' => '\underline', '⏟' => '\underbrace', '︸' => '\underbrace',
        '→' => '\underrightarrow', '←' => '\underleftarrow',
    ];

    /** @var array Opening fence => \left form. */
    const OPENFENCES = ['(' => '(', '[' => '[', '{' => '\{', '|' => '|', '‖' => '\|', '⟨' => '\langle',
        '〈' => '\langle', '⌊' => '\lfloor', '⌈' => '\lceil', '[[' => '[', '⟦' => '[', ']' => ']'];

    /** @var array Closing fence => \right form. */
    const CLOSEFENCES = [')' => ')', ']' => ']', '}' => '\}', '|' => '|', '‖' => '\|', '⟩' => '\rangle',
        '〉' => '\rangle', '⌋' => '\rfloor', '⌉' => '\rceil', '⟧' => ']', '[' => '['];

    /** @var string[] Stack of inherited mathvariant values. */
    protected $variants = [];

    /** @var string[] Warnings gathered during the conversion (unknown elements...). */
    public $warnings = [];

    /**
     * Converts a MathML string.
     *
     * @param string $mathml well-formed MathML (a <math> element)
     * @return array ['latex' => string, 'display' => bool, 'fromannotation' => bool]
     * @throws \InvalidArgumentException if the MathML cannot be parsed
     */
    public function convert(string $mathml): array {
        $this->warnings = [];
        $this->variants = [];
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $doc->loadXML(self::xml_safe_entities($mathml), LIBXML_NONET | LIBXML_NOBLANKS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || !$doc->documentElement) {
            $msg = $errors ? trim($errors[0]->message) : 'unknown error';
            throw new \InvalidArgumentException('Invalid MathML: ' . $msg);
        }
        $root = $doc->documentElement;
        if ($root->localName !== 'math') {
            throw new \InvalidArgumentException('Root element is not <math>');
        }
        $display = ($root->getAttribute('display') === 'block' || $root->getAttribute('mode') === 'display');

        // A LaTeX annotation typed by the teacher is the best possible source.
        $annotation = $this->find_latex_annotation($root);
        if ($annotation !== null) {
            return ['latex' => $annotation, 'display' => $display, 'fromannotation' => true];
        }

        $children = $this->children($root);
        // Unwrap a single plain mrow / semantics to look for top level line breaks.
        while (count($children) === 1 && (($children[0]->localName === 'mrow' && !$children[0]->hasAttributes())
                || $children[0]->localName === 'semantics')) {
            $inner = $this->children($children[0]);
            if ($children[0]->localName === 'semantics') {
                $inner = $inner ? [$inner[0]] : [];
            }
            $children = $inner;
        }
        $latex = $this->lines($children, true);
        $latex = trim(preg_replace('/ {2,}/', ' ', $latex));
        return ['latex' => $latex, 'display' => $display, 'fromannotation' => false];
    }

    /**
     * Replaces HTML named entities (not known by XML) with numeric references.
     *
     * @param string $xml
     * @return string
     */
    public static function xml_safe_entities(string $xml): string {
        // Bare ampersands.
        $xml = preg_replace('/&(?!#[0-9]+;|#x[0-9a-fA-F]+;|[a-zA-Z][a-zA-Z0-9]*;)/', '&amp;', $xml);
        return preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]*);/', function($m) {
            if (in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'apos'])) {
                return $m[0];
            }
            $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $m[0]) {
                return '&amp;' . $m[1] . ';';
            }
            $out = '';
            foreach (mb_str_split($decoded) as $char) {
                $out .= '&#' . mb_ord($char, 'UTF-8') . ';';
            }
            return $out;
        }, $xml);
    }

    /**
     * Looks for <annotation encoding="LaTeX"> directly under the top level semantics element.
     *
     * @param \DOMElement $root
     * @return string|null
     */
    protected function find_latex_annotation(\DOMElement $root): ?string {
        $node = $root;
        // math > semantics, or math > mrow > semantics.
        for ($i = 0; $i < 3; $i++) {
            $children = $this->children($node);
            if (count($children) !== 1) {
                return null;
            }
            $node = $children[0];
            if ($node->localName === 'semantics') {
                foreach ($this->children($node) as $child) {
                    if ($child->localName === 'annotation') {
                        $enc = strtolower($child->getAttribute('encoding'));
                        if (in_array($enc, ['latex', 'tex', 'application/x-tex', 'text/x-latex'])) {
                            $tex = trim($child->textContent);
                            return $tex === '' ? null : $tex;
                        }
                    }
                }
                return null;
            }
            if ($node->localName !== 'mrow') {
                return null;
            }
        }
        return null;
    }

    /**
     * Element children of a node.
     *
     * @param \DOMNode $node
     * @return \DOMElement[]
     */
    protected function children(\DOMNode $node): array {
        $result = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * Converts a sequence that may contain <mspace linebreak="newline"/>.
     *
     * @param \DOMElement[] $children
     * @param bool $top
     * @return string
     */
    protected function lines(array $children, bool $top): string {
        $lines = [[]];
        foreach ($children as $child) {
            if ($child->localName === 'mspace' && $child->getAttribute('linebreak') === 'newline') {
                $lines[] = [];
            } else {
                $lines[count($lines) - 1][] = $child;
            }
        }
        $lines = array_values(array_filter($lines, function($l) {
            return count($l) > 0;
        }));
        if (count($lines) <= 1) {
            return $this->sequence($lines[0] ?? []);
        }
        $out = [];
        foreach ($lines as $line) {
            $out[] = $this->sequence($line);
        }
        return '\begin{array}{l} ' . implode(' \\\\ ', $out) . ' \end{array}';
    }

    /**
     * Converts a list of sibling elements (content of an mrow).
     *
     * @param \DOMElement[] $children
     * @return string
     */
    protected function sequence(array $children): string {
        $n = count($children);
        $parts = [];
        $i = 0;
        while ($i < $n) {
            $child = $children[$i];
            $open = $this->fence_char($child, true);
            if ($open !== null) {
                $j = $this->matching($children, $i);
                if ($j !== null) {
                    $close = $this->fence_char($children[$j], false);
                    $parts[] = $this->fenced($open, $close, array_slice($children, $i + 1, $j - $i - 1), $child,
                        $children[$j]);
                    $i = $j + 1;
                    continue;
                }
                // Left brace alone before a table: piecewise function or system of equations.
                $rest = array_slice($children, $i + 1);
                if ($open === '{' && $this->contains($rest, ['mtable'])) {
                    $parts[] = '\left\{ ' . $this->sequence($rest) . ' \right.';
                    break;
                }
            }
            $parts[] = $this->node($child, $children[$i - 1] ?? null, $children[$i + 1] ?? null);
            $i++;
        }
        return self::join($parts);
    }

    /**
     * Finds the index of the fence closing the one at position $start.
     *
     * @param \DOMElement[] $children
     * @param int $start
     * @return int|null
     */
    protected function matching(array $children, int $start): ?int {
        $n = count($children);
        $open = $this->fence_char($children[$start], true);
        if ($open === '|' || $open === '‖') {
            // Absolute value / norm: the next identical bar closes it.
            for ($k = $start + 2; $k < $n; $k++) {
                if ($children[$k]->localName === 'mo' && trim($children[$k]->textContent) === $open) {
                    return $k;
                }
            }
            return null;
        }
        $depth = 0;
        for ($k = $start; $k < $n; $k++) {
            $c = $children[$k];
            if ($c->localName !== 'mo') {
                continue;
            }
            $t = trim($c->textContent);
            if ($t === '|' || $t === '‖') {
                continue;
            }
            if ($k > $start && $this->fence_char($c, false) !== null) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            } else if ($this->fence_char($c, true) !== null) {
                $depth++;
            }
        }
        return null;
    }

    /**
     * Returns the fence character of an <mo>, if it is a fence of the requested side.
     *
     * @param \DOMElement $el
     * @param bool $opening
     * @return string|null
     */
    protected function fence_char(\DOMElement $el, bool $opening): ?string {
        if ($el->localName !== 'mo') {
            return null;
        }
        $t = trim($el->textContent);
        $fences = $opening ? self::OPENFENCES : self::CLOSEFENCES;
        if (!array_key_exists($t, $fences)) {
            return null;
        }
        // Reversed brackets like ]a,b[ (French intervals) are only used when explicitly marked as fences.
        if (($opening && $t === ']') || (!$opening && $t === '[')) {
            if ($el->getAttribute('fence') !== 'true') {
                return null;
            }
        }
        return $t;
    }

    /**
     * Produces a fenced group.
     *
     * @param string $open opening character
     * @param string $close closing character
     * @param \DOMElement[] $inner
     * @param \DOMElement|null $openel
     * @param \DOMElement|null $closeel
     * @return string
     */
    protected function fenced(string $open, string $close, array $inner, ?\DOMElement $openel = null,
            ?\DOMElement $closeel = null): string {
        // Binomial coefficient.
        if ($open === '(' && $close === ')' && count($inner) === 1 && $inner[0]->localName === 'mfrac'
                && $this->is_zero_thickness($inner[0])) {
            $parts = $this->children($inner[0]);
            if (count($parts) === 2) {
                return '\binom{' . $this->node($parts[0]) . '}{' . $this->node($parts[1]) . '}';
            }
        }
        $content = $this->sequence($inner);
        $stretchy = true;
        foreach ([$openel, $closeel] as $el) {
            if ($el && $el->getAttribute('stretchy') === 'false') {
                $stretchy = false;
            }
        }
        $tall = $stretchy && $this->contains($inner, ['mfrac', 'mtable', 'munderover', 'munder', 'mover',
                'msqrt', 'mroot', 'msubsup', 'mstyle']);
        if ($tall) {
            return '\left' . self::OPENFENCES[$open] . ' ' . $content . ' \right' . self::CLOSEFENCES[$close];
        }
        return self::join([$this->plain_fence($open), $content, $this->plain_fence($close)]);
    }

    /**
     * Non stretchy fence.
     *
     * @param string $char
     * @return string
     */
    protected function plain_fence(string $char): string {
        $map = ['{' => '\{', '}' => '\}', '‖' => '\|', '⟨' => '\langle', '⟩' => '\rangle', '〈' => '\langle',
            '〉' => '\rangle', '⌊' => '\lfloor', '⌋' => '\rfloor', '⌈' => '\lceil', '⌉' => '\rceil', '⟦' => '[',
            '⟧' => ']'];
        return $map[$char] ?? $char;
    }

    /**
     * Whether one of the elements (or their descendants) has one of the given names.
     *
     * @param \DOMElement[] $elements
     * @param string[] $names
     * @return bool
     */
    protected function contains(array $elements, array $names): bool {
        foreach ($elements as $el) {
            if (in_array($el->localName, $names)) {
                if ($el->localName !== 'mstyle' || $el->getAttribute('displaystyle') === 'true') {
                    return true;
                }
            }
            if ($this->contains($this->children($el), $names)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an mfrac has no fraction bar.
     *
     * @param \DOMElement $frac
     * @return bool
     */
    protected function is_zero_thickness(\DOMElement $frac): bool {
        $lt = trim($frac->getAttribute('linethickness'));
        return $lt !== '' && preg_match('/^0+(\.0*)?([a-z]+)?$/', $lt);
    }

    /**
     * Concatenates LaTeX fragments, inserting a space only when needed (after a control word).
     *
     * @param string[] $parts
     * @return string
     */
    public static function join(array $parts): string {
        $out = '';
        foreach ($parts as $p) {
            if ($p === '' || $p === null) {
                continue;
            }
            if ($out !== '' && preg_match('/\\\\[a-zA-Z]+$/', $out) && preg_match('/^[a-zA-Z]/', $p)) {
                $out .= ' ';
            }
            $out .= $p;
        }
        return $out;
    }

    /**
     * Converts the characters of a token.
     *
     * @param string $text
     * @return string
     */
    protected function chars(string $text): string {
        $parts = [];
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if (array_key_exists($char, self::SYMBOLS)) {
                $parts[] = self::SYMBOLS[$char];
            } else if ($char === "\n" || $char === "\r" || $char === "\t") {
                $parts[] = ' ';
            } else {
                $parts[] = $char;
            }
        }
        return self::join($parts);
    }

    /**
     * Converts the element as a group argument (always braced).
     *
     * @param \DOMElement|null $el
     * @return string
     */
    protected function arg(?\DOMElement $el): string {
        return '{' . ($el ? $this->node($el) : '') . '}';
    }

    /**
     * Converts an element used as the base of scripts, bracing it when needed.
     *
     * @param \DOMElement $el
     * @return string
     */
    protected function base(\DOMElement $el): string {
        $latex = $this->node($el);
        if ($latex === '') {
            return '{}';
        }
        if ($this->is_atomic($el)) {
            return $latex;
        }
        return '{' . $latex . '}';
    }

    /**
     * Whether an element converts to a single TeX atom, safe to use as a script base without braces.
     *
     * @param \DOMElement $el
     * @return bool
     */
    protected function is_atomic(\DOMElement $el): bool {
        switch ($el->localName) {
            case 'mi':
            case 'mo':
                return true;
            case 'mn':
                return true;
            case 'mfrac':
            case 'msqrt':
            case 'mroot':
            case 'mtext':
            case 'mfenced':
            case 'menclose':
            case 'mtable':
                return !$el->hasAttribute('mathcolor');
            case 'mover':
            case 'munder':
            case 'munderover':
                return false;
            case 'mrow':
            case 'mstyle':
            case 'mpadded':
                if ($el->hasAttribute('mathcolor') || $el->getAttribute('displaystyle') === 'true') {
                    return false;
                }
                $children = $this->children($el);
                if (count($children) === 1) {
                    return $this->is_atomic($children[0]);
                }
                $n = count($children);
                if ($n >= 2 && $this->fence_char($children[0], true) !== null
                        && $this->matching($children, 0) === $n - 1) {
                    return true;
                }
                return false;
        }
        return false;
    }

    /**
     * Current inherited mathvariant.
     *
     * @param \DOMElement $el
     * @return string
     */
    protected function variant(\DOMElement $el): string {
        if ($el->hasAttribute('mathvariant')) {
            return $el->getAttribute('mathvariant');
        }
        return $this->variants ? end($this->variants) : '';
    }

    /**
     * Converts one element.
     *
     * @param \DOMElement $el
     * @param \DOMElement|null $prev previous sibling (for context dependent tokens)
     * @param \DOMElement|null $next next sibling
     * @return string
     */
    protected function node(\DOMElement $el, ?\DOMElement $prev = null, ?\DOMElement $next = null): string {
        $pushed = false;
        if ($el->hasAttribute('mathvariant') && !in_array($el->localName, ['mi', 'mn', 'mo', 'mtext'])) {
            $this->variants[] = $el->getAttribute('mathvariant');
            $pushed = true;
        }
        $latex = $this->node_inner($el, $prev, $next);
        if ($pushed) {
            array_pop($this->variants);
        }
        $color = $el->getAttribute('mathcolor') ?: $el->getAttribute('color');
        if ($color !== '' && $latex !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]+)$/', $color)
                && !in_array(strtolower($color), ['#000', '#000000', 'black'])) {
            $latex = '{\color{' . $color . '}{' . $latex . '}}';
        }
        return $latex;
    }

    /**
     * Converts one element, without colour handling.
     *
     * @param \DOMElement $el
     * @param \DOMElement|null $prev
     * @param \DOMElement|null $next
     * @return string
     */
    protected function node_inner(\DOMElement $el, ?\DOMElement $prev, ?\DOMElement $next): string {
        $children = $this->children($el);
        switch ($el->localName) {
            case 'math':
            case 'mrow':
            case 'mpadded':
            case 'merror':
                return $this->lines($children, false);

            case 'mstyle':
                $content = $this->lines($children, false);
                if ($el->getAttribute('displaystyle') === 'true') {
                    return '{\displaystyle ' . $content . '}';
                }
                if ($el->getAttribute('displaystyle') === 'false' && $content !== '') {
                    return '{\textstyle ' . $content . '}';
                }
                return $content;

            case 'semantics':
                return $children ? $this->node($children[0]) : '';

            case 'annotation':
            case 'annotation-xml':
            case 'none':
            case 'mprescripts':
            case 'malignmark':
            case 'maligngroup':
                return '';

            case 'mphantom':
                return '\phantom{' . $this->lines($children, false) . '}';

            case 'maction':
                $sel = (int)($el->getAttribute('selection') ?: 1);
                return isset($children[$sel - 1]) ? $this->node($children[$sel - 1]) : '';

            case 'mi':
                return $this->mi($el);

            case 'mn':
                $text = trim($el->textContent);
                $latex = $this->chars($text);
                $latex = preg_replace('/(?<=[0-9]),(?=[0-9])/', '{,}', $latex);
                return $this->apply_variant($latex, $this->variant($el), $text);

            case 'mo':
                return $this->mo($el, $prev, $next);

            case 'mtext':
            case 'ms':
                return $this->mtext($el);

            case 'mspace':
                return $this->mspace($el);

            case 'mglyph':
                return $this->text_command($el->getAttribute('alt'));

            case 'mfrac':
                if (count($children) < 2) {
                    return $this->sequence($children);
                }
                if ($el->getAttribute('bevelled') === 'true') {
                    return $this->base($children[0]) . '/' . $this->base($children[1]);
                }
                if ($this->is_zero_thickness($el)) {
                    return '\genfrac{}{}{0pt}{}' . $this->arg($children[0]) . $this->arg($children[1]);
                }
                return '\frac' . $this->arg($children[0]) . $this->arg($children[1]);

            case 'msqrt':
                return '\sqrt{' . $this->lines($children, false) . '}';

            case 'mroot':
                if (count($children) < 2) {
                    return '\sqrt{' . $this->sequence($children) . '}';
                }
                return '\sqrt[' . $this->node($children[1]) . ']' . $this->arg($children[0]);

            case 'msub':
                if (count($children) < 2) {
                    return $this->sequence($children);
                }
                return $this->base($children[0]) . '_' . $this->arg($children[1]);

            case 'msup':
                if (count($children) < 2) {
                    return $this->sequence($children);
                }
                $sup = $this->token_text($children[1]);
                if ($sup !== null && in_array($sup, ['′', '″', '‴', "'", "''"])) {
                    $n = ['′' => 1, '″' => 2, '‴' => 3, "'" => 1, "''" => 2][$sup];
                    return $this->base($children[0]) . str_repeat("'", $n);
                }
                if ($sup === '°' || $sup === '∘') {
                    return $this->base($children[0]) . '^{\circ}';
                }
                // Ions and indexed powers: x_{n}^{2} rather than {x_{n}}^{2}.
                if ($children[0]->localName === 'msub' && !$children[0]->hasAttribute('mathcolor')) {
                    $inner = $this->children($children[0]);
                    if (count($inner) === 2) {
                        return $this->base($inner[0]) . '_' . $this->arg($inner[1]) . '^' . $this->arg($children[1]);
                    }
                }
                return $this->base($children[0]) . '^' . $this->arg($children[1]);

            case 'msubsup':
                if (count($children) < 3) {
                    return $this->sequence($children);
                }
                return $this->base($children[0]) . '_' . $this->arg($children[1]) . '^' . $this->arg($children[2]);

            case 'munder':
            case 'mover':
            case 'munderover':
                return $this->underover($el, $children);

            case 'mmultiscripts':
                return $this->multiscripts($children);

            case 'mfenced':
                $open = $el->hasAttribute('open') ? $el->getAttribute('open') : '(';
                $close = $el->hasAttribute('close') ? $el->getAttribute('close') : ')';
                $seps = $el->hasAttribute('separators') ? $el->getAttribute('separators') : ',';
                $seps = preg_split('//u', preg_replace('/\s+/', '', $seps), -1, PREG_SPLIT_NO_EMPTY);
                $items = [];
                foreach ($children as $i => $child) {
                    if ($i > 0 && $seps) {
                        $items[] = $this->chars($seps[min($i - 1, count($seps) - 1)]);
                    }
                    $items[] = $this->node($child);
                }
                $content = self::join($items);
                $tall = $this->contains($children, ['mfrac', 'mtable', 'munderover', 'munder', 'mover', 'msqrt',
                    'mroot', 'msubsup', 'mstyle']);
                if ($open === '(' && $close === ')' && count($children) === 1 && $children[0]->localName === 'mfrac'
                        && $this->is_zero_thickness($children[0])) {
                    $parts = $this->children($children[0]);
                    if (count($parts) === 2) {
                        return '\binom{' . $this->node($parts[0]) . '}{' . $this->node($parts[1]) . '}';
                    }
                }
                if ($tall || $open === '' || $close === '') {
                    $l = $open === '' ? '.' : (self::OPENFENCES[$open] ?? $this->chars($open));
                    $r = $close === '' ? '.' : (self::CLOSEFENCES[$close] ?? $this->chars($close));
                    return '\left' . $l . ' ' . $content . ' \right' . $r;
                }
                return self::join([$this->plain_fence($open), $content, $this->plain_fence($close)]);

            case 'mtable':
                return $this->mtable($el, $children);

            case 'mtr':
            case 'mlabeledtr':
            case 'mtd':
                return $this->lines($children, false);

            case 'menclose':
                return $this->menclose($el, $children);

            case 'mstack':
            case 'mlongdiv':
            case 'msgroup':
            case 'msrow':
            case 'mscarries':
            case 'mscarry':
            case 'msline':
                $this->warnings[] = 'Elementary math layout <' . $el->localName . '> simplified';
                return $this->sequence($children);

            default:
                $this->warnings[] = 'Unknown element <' . $el->localName . '>';
                return $this->sequence($children);
        }
    }

    /**
     * Identifier.
     *
     * @param \DOMElement $el
     * @return string
     */
    protected function mi(\DOMElement $el): string {
        $text = trim($el->textContent);
        if ($text === '') {
            return '';
        }
        $variant = $this->variant($el);
        if (mb_strlen($text) === 1) {
            return $this->apply_variant($this->chars($text), $variant, $text);
        }
        if (in_array($text, self::FUNCTIONS)) {
            return '\\' . $text;
        }
        if (in_array($text, self::OPERATORNAMES)) {
            return '\operatorname{' . $text . '}';
        }
        if ($variant === 'italic') {
            return $this->chars($text);
        }
        if (in_array($variant, ['bold', 'bold-italic', 'double-struck', 'script', 'fraktur', 'sans-serif',
                'monospace'])) {
            return $this->apply_variant($this->chars($text), $variant, $text);
        }
        return '\mathrm{' . $this->chars($text) . '}';
    }

    /**
     * Applies a mathvariant to a converted token.
     *
     * @param string $latex converted token
     * @param string $variant mathvariant value
     * @param string $raw original text
     * @return string
     */
    protected function apply_variant(string $latex, string $variant, string $raw): string {
        if ($variant === '' || $latex === '') {
            return $latex;
        }
        $ascii = preg_match('/^[A-Za-z0-9]+$/', $raw);
        switch ($variant) {
            case 'normal':
                return $ascii && preg_match('/[A-Za-z]/', $raw) ? '\mathrm{' . $latex . '}' : $latex;
            case 'bold':
                return $ascii ? '\mathbf{' . $latex . '}' : '\boldsymbol{' . $latex . '}';
            case 'bold-italic':
                return '\boldsymbol{' . $latex . '}';
            case 'double-struck':
                return $ascii ? '\mathbb{' . $latex . '}' : $latex;
            case 'script':
            case 'bold-script':
                return $ascii ? '\mathcal{' . $latex . '}' : $latex;
            case 'fraktur':
            case 'bold-fraktur':
                return $ascii ? '\mathfrak{' . $latex . '}' : $latex;
            case 'sans-serif':
                return $ascii ? '\mathsf{' . $latex . '}' : $latex;
            case 'monospace':
                return $ascii ? '\mathtt{' . $latex . '}' : $latex;
        }
        return $latex;
    }

    /**
     * Operator.
     *
     * @param \DOMElement $el
     * @param \DOMElement|null $prev
     * @param \DOMElement|null $next
     * @return string
     */
    protected function mo(\DOMElement $el, ?\DOMElement $prev, ?\DOMElement $next): string {
        $text = trim($el->textContent);
        if ($text === '') {
            // A space character trimmed away.
            return $el->textContent !== '' ? '\ ' : '';
        }
        if (in_array($text, self::FUNCTIONS)) {
            return '\\' . $text;
        }
        if (in_array($text, self::OPERATORNAMES)) {
            return '\operatorname{' . $text . '}';
        }
        if ($text === '°') {
            return ($prev && !in_array($prev->localName, ['msup', 'msubsup', 'mmultiscripts'])) ? '^{\circ}' : '{}^{\circ}';
        }
        if ($text === '′' || $text === '″' || $text === '‴') {
            $primes = str_repeat("'", mb_strlen($text));
            return ($prev && !in_array($prev->localName, ['msup', 'msubsup'])) ? $primes : '{}' . $primes;
        }
        if ($text === "'") {
            return "'";
        }
        if (mb_strlen($text) > 1 && preg_match('/^[A-Za-z]+$/', $text)) {
            return '\operatorname{' . $text . '}';
        }
        $latex = $this->chars($text);
        if ($el->getAttribute('largeop') === 'true' && in_array($text, self::INTEGRALS)) {
            return $latex;
        }
        return $latex;
    }

    /**
     * Text.
     *
     * @param \DOMElement $el
     * @return string
     */
    protected function mtext(\DOMElement $el): string {
        $text = $el->textContent;
        if ($el->localName === 'ms') {
            $text = '"' . $text . '"';
        }
        $text = str_replace(["\u{2061}", "\u{2062}", "\u{2063}", "\u{2064}", "\u{200B}"], '', $text);
        $text = preg_replace('/[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}]+/u', ' ', $text);
        if (trim($text) === '') {
            return $text === '' ? '' : '\;';
        }
        $variant = $this->variant($el);
        $latex = $this->text_command($text);
        if ($variant === 'bold' || $variant === 'bold-italic') {
            $latex = '\boldsymbol{' . $latex . '}';
        }
        return $latex;
    }

    /**
     * Builds \text{...}, taking care of characters that MathJax does not accept inside \text.
     *
     * @param string $text
     * @return string
     */
    protected function text_command(string $text): string {
        if ($text === '') {
            return '';
        }
        $pieces = preg_split('/([{}$\\\\])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($pieces as $piece) {
            switch ($piece) {
                case '{':
                    $out[] = '\{';
                    break;
                case '}':
                    $out[] = '\}';
                    break;
                case '$':
                    $out[] = '\$';
                    break;
                case '\\':
                    $out[] = '\backslash';
                    break;
                default:
                    $out[] = '\text{' . $piece . '}';
            }
        }
        return self::join($out);
    }

    /**
     * Space.
     *
     * @param \DOMElement $el
     * @return string
     */
    protected function mspace(\DOMElement $el): string {
        if ($el->getAttribute('linebreak') === 'newline') {
            return '\ ';
        }
        $width = trim($el->getAttribute('width'));
        if ($width === '') {
            return '';
        }
        $named = ['veryverythinmathspace' => 1 / 18, 'verythinmathspace' => 2 / 18, 'thinmathspace' => 3 / 18,
            'mediummathspace' => 4 / 18, 'thickmathspace' => 5 / 18, 'verythickmathspace' => 6 / 18,
            'veryverythickmathspace' => 7 / 18];
        if (isset($named[$width])) {
            $em = $named[$width];
        } else if (preg_match('/^(-?[0-9.]+)\s*(em|ex|px|pt|mm|cm|in)?$/', $width, $m)) {
            $value = (float)$m[1];
            $unit = $m[2] ?? 'em';
            $factors = ['em' => 1, 'ex' => 0.43, 'px' => 1 / 16, 'pt' => 0.1, 'mm' => 0.28, 'cm' => 2.8, 'in' => 7.2,
                '' => 1];
            $em = $value * ($factors[$unit] ?? 1);
        } else {
            return '\ ';
        }
        if ($em <= 0) {
            return $em < 0 ? '\!' : '';
        }
        if ($em < 0.2) {
            return '\,';
        }
        if ($em < 0.25) {
            return '\:';
        }
        if ($em < 0.5) {
            return '\;';
        }
        if ($em < 1.5) {
            return '\quad';
        }
        return '\qquad';
    }

    /**
     * Text of a token element if the element is a single token (possibly wrapped in mrow), else null.
     *
     * @param \DOMElement $el
     * @return string|null
     */
    protected function token_text(\DOMElement $el): ?string {
        while (in_array($el->localName, ['mrow', 'mstyle']) && count($this->children($el)) === 1) {
            $el = $this->children($el)[0];
        }
        if (in_array($el->localName, ['mo', 'mi', 'mn', 'mtext'])) {
            return trim($el->textContent);
        }
        return null;
    }

    /**
     * munder, mover, munderover.
     *
     * @param \DOMElement $el
     * @param \DOMElement[] $children
     * @return string
     */
    protected function underover(\DOMElement $el, array $children): string {
        $name = $el->localName;
        $needed = $name === 'munderover' ? 3 : 2;
        if (count($children) < $needed) {
            return $this->sequence($children);
        }
        $base = $children[0];
        $under = $name === 'mover' ? null : $children[1];
        $over = $name === 'mover' ? $children[1] : ($name === 'munderover' ? $children[2] : null);
        $basetext = $this->token_text($base);

        // Big operators and limits: use scripts.
        if ($basetext !== null && (in_array($basetext, self::LARGEOPS) || in_array($basetext, self::INTEGRALS))) {
            $latex = $this->node($base);
            if (in_array($basetext, self::INTEGRALS)) {
                $latex .= '\limits';
            }
            if ($under) {
                $latex .= '_' . $this->arg($under);
            }
            if ($over) {
                $latex .= '^' . $this->arg($over);
            }
            return $latex;
        }

        // Extensible arrows with text (chemistry, mappings).
        if ($basetext !== null && in_array($basetext, ['→', '⟶', '←', '⟵', '⇌', '⇄', '↔'])) {
            $cmd = ['→' => '\xrightarrow', '⟶' => '\xrightarrow', '←' => '\xleftarrow', '⟵' => '\xleftarrow'];
            if (isset($cmd[$basetext])) {
                $o = $over ? $this->node($over) : '';
                $u = $under ? $this->node($under) : '';
                return $cmd[$basetext] . ($u !== '' ? '[' . $u . ']' : '') . '{' . $o . '}';
            }
        }

        // Accents.
        if ($name === 'mover') {
            $accent = $this->token_text($over);
            if ($accent !== null && isset(self::OVERACCENTS[$accent])) {
                $baselatex = $this->node($base);
                $narrow = $this->is_single_symbol($baselatex);
                $cmd = self::OVERACCENTS[$accent][$narrow ? 0 : 1];
                return $cmd . '{' . $baselatex . '}';
            }
        }
        if ($name === 'munder') {
            $accent = $this->token_text($under);
            if ($accent !== null && isset(self::UNDERACCENTS[$accent])) {
                return self::UNDERACCENTS[$accent] . '{' . $this->node($base) . '}';
            }
        }

        $baselatex = $this->node($base);
        // Labels of \underbrace / \overbrace go in scripts.
        if (strpos($baselatex, '\underbrace{') === 0 && $under && !$over) {
            return $baselatex . '_' . $this->arg($under);
        }
        if (strpos($baselatex, '\overbrace{') === 0 && $over && !$under) {
            return $baselatex . '^' . $this->arg($over);
        }
        $latex = $baselatex;
        if ($under) {
            $latex = '\underset' . $this->arg($under) . '{' . $latex . '}';
        }
        if ($over) {
            $latex = '\overset' . $this->arg($over) . '{' . $latex . '}';
        }
        return $latex;
    }

    /**
     * Whether a LaTeX fragment is a single letter/symbol (for narrow accents).
     *
     * @param string $latex
     * @return bool
     */
    protected function is_single_symbol(string $latex): bool {
        return (bool)preg_match('/^([A-Za-z0-9]|\\\\[A-Za-z]+|\\\\math[a-z]+\{[A-Za-z]\})$/', $latex);
    }

    /**
     * mmultiscripts (isotopes, tensors).
     *
     * @param \DOMElement[] $children
     * @return string
     */
    protected function multiscripts(array $children): string {
        if (!$children) {
            return '';
        }
        $base = array_shift($children);
        $post = [];
        $pre = [];
        $target = &$post;
        foreach ($children as $child) {
            if ($child->localName === 'mprescripts') {
                $target = &$pre;
                continue;
            }
            $target[] = $child;
        }
        unset($target);
        $scripts = function(array $list): string {
            $out = '';
            for ($i = 0; $i < count($list); $i += 2) {
                $sub = $this->node($list[$i]);
                $sup = isset($list[$i + 1]) ? $this->node($list[$i + 1]) : '';
                if ($sub !== '') {
                    $out .= '_{' . $sub . '}';
                }
                if ($sup !== '') {
                    $out .= '^{' . $sup . '}';
                }
            }
            return $out;
        };
        $prelatex = $scripts($pre);
        $postlatex = $scripts($post);
        $latex = $prelatex !== '' ? '{}' . $prelatex : '';
        return $latex . $this->base($base) . $postlatex;
    }

    /**
     * Tables and matrices.
     *
     * @param \DOMElement $el
     * @param \DOMElement[] $rows
     * @return string
     */
    protected function mtable(\DOMElement $el, array $rows): string {
        $lines = [];
        $maxcols = 0;
        foreach ($rows as $row) {
            if (!in_array($row->localName, ['mtr', 'mlabeledtr'])) {
                // Invalid structure: treat as a one cell row.
                $lines[] = [$this->node($row)];
                $maxcols = max($maxcols, 1);
                continue;
            }
            $cells = $this->children($row);
            if ($row->localName === 'mlabeledtr') {
                array_shift($cells);
            }
            $line = [];
            foreach ($cells as $cell) {
                $line[] = $this->node($cell);
            }
            $lines[] = $line;
            $maxcols = max($maxcols, count($line));
        }
        if ($maxcols === 0) {
            return '';
        }
        $aligns = preg_split('/\s+/', trim($el->getAttribute('columnalign')), -1, PREG_SPLIT_NO_EMPTY);
        $spec = '';
        for ($i = 0; $i < $maxcols; $i++) {
            $a = $aligns ? $aligns[min($i, count($aligns) - 1)] : 'center';
            $spec .= ['left' => 'l', 'right' => 'r'][$a] ?? 'c';
        }
        $body = [];
        foreach ($lines as $line) {
            $body[] = implode(' & ', $line);
        }
        return '\begin{array}{' . $spec . '} ' . implode(' \\\\ ', $body) . ' \end{array}';
    }

    /**
     * menclose.
     *
     * @param \DOMElement $el
     * @param \DOMElement[] $children
     * @return string
     */
    protected function menclose(\DOMElement $el, array $children): string {
        $content = $this->lines($children, false);
        $notation = trim($el->getAttribute('notation')) ?: 'longdiv';
        $notations = preg_split('/\s+/', $notation);
        sort($notations);
        $key = implode(' ', $notations);
        switch ($key) {
            case 'box':
            case 'roundedbox':
            case 'bottom left right top':
                return '\boxed{' . $content . '}';
            case 'updiagonalstrike':
                return '\cancel{' . $content . '}';
            case 'downdiagonalstrike':
                return '\bcancel{' . $content . '}';
            case 'downdiagonalstrike updiagonalstrike':
                return '\xcancel{' . $content . '}';
            case 'top':
                return '\overline{' . $content . '}';
            case 'bottom':
                return '\underline{' . $content . '}';
        }
        return '\enclose{' . implode(',', $notations) . '}{' . $content . '}';
    }
}
