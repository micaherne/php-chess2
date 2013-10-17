<?php

class Context {
	
	public $board;
	public $pieces;
	
	public $whiteToMove;
	
	public static $pieceAlias = array(
	    'P' => 'WhitePawn',
		'N' => 'WhiteKnight',
		'B' => 'WhiteBishop',
		'R' => 'WhiteRook',
		'Q' => 'WhiteQueen',
		'K' => 'WhiteKing',
		'p' => 'BlackPawn',
		'n' => 'BlackKnight',
		'b' => 'BlackBishop',
		'r' => 'BlackRook',
		'q' => 'BlackQueen',
		'k' => 'BlackKing'
	);
	
	public function __construct() {
		$this->_init();
	}
	
	private function _init() {
		$this->board = array();
		for ($i = 0; $i < 128; $i++) {
			$this->board[] = null;
		}
		$this->pieces = array();
	}
	
	/**
	 * Set up the board for the start of a game
     */
	public function startPosition() {
		$this->fromFEN('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1');
	}
	
	/**
	 * Set up a position from a FEN string.
	 * 
	 * @param $fenString a Forsyth-Edwards notation string for a position
	 * @param $validate throw an Exception if the FEN is invalid?
	 */
	public function fromFEN($fenString, $validate=true) {
		$parts = preg_split('/\s+/', $fenString);

		if ($validate && count($parts) < 4) {
			throw new FENException("FEN must have at least 4 parts");
		}
		
		$lines = explode('/', $parts[0]);
		if ($validate && count($lines) != 8) {
			throw new FENException("Board representation must have 8 parts");
		}
		
		$rank = 7;
		foreach ($lines as $line) {
			$file = 0;
			foreach (str_split($line) as $char) {
				if (is_numeric($char)) {
					$file += $char;
				} else {
					if ($validate && !array_key_exists($char, self::$pieceAlias)) {
						throw new FENException("Invalid piece type $char");
					}
					$this->setPiece(self::squareIndex($file, $rank), $char);
					$file++;
				}
			}
			$rank--;
		}
		
		if ($parts[1] == 'w') {
			$this->whiteToMove = true;
		} else if ($parts[1] == 'b') {
			$this->whiteToMove == false;
		} else if ($validate) {
			throw new FENException("Invalid side to move {$parts[1]}");
		}
		
		// TODO: Other FEN parts
	}
	
	/**
	 * Generate pseudo-legal moves for a given piece
	 */
	public function generatePseudoLegalMoves(Piece $piece) {
		$result = array();
		if (is_null($piece) || is_null($piece->location)) {
			return $result;
		}
		foreach ($piece->rules as $rule) {
			if (!$rule->prerequisite->satisfied($this, $piece)) {
				continue;
			}
			foreach ($rule->moveType->generateMoves($this, $piece) as $move) {
				$move->result = $rule->result;
				$result[] = $move;
			}
		}
		return $result;
	}
	
	/**
	 * Generate pseudo-legal moves for a whole position
	 */
	public function generateAllPseudoLegalMoves() {
		$colourToMove = null;
		if ($this->whiteToMove === true) {
			$colourToMove = Piece::WHITE;
		} else if ($this->whiteToMove === false){
			$colourToMove = Piece::BLACK;
		} else {
			throw new Exception("Invalid colour to move");
		}
		$result = array();
		foreach ($this->pieces as $piece) {
			if ($piece->colour == $colourToMove && !is_null($piece->location)) {
				$result = array_merge($result, $this->generatePseudoLegalMoves($piece));
			}
		}
		return $result;
	}
	
	/**
	 * Return a visual representation of the board.
	 * 
	 * @return string
	 */
	public function display() {
		$result = $sep = "+-+-+-+-+-+-+-+-+\n";
		for ($rank = 7; $rank >= 0; $rank--) {
			$result .= "|";
			for ($file = 0; $file < 8; $file++) {
				$piece = $this->board[self::squareIndex($file, $rank)];
				if (is_null($piece)) {
					$result .= ' ';
				} else {
					$result .= $piece->algebraicSymbol();
				}
				$result .= "|";
			}
			$result .= "\n$sep";
		}
		return $result;
	}

	/**
	 * Set the given piece type on the given square.
	 * @param unknown $square
	 * @param string $type either a full class name or an alias
	 */
	public function setPiece($square, $type) {
		$piece = $this->getUnusedPiece($type);
		$piece->location = $square;
		$this->board[$square] = $piece;
	}
	
	/**
	 * Get a piece of the given type. This will look for existing pieces which are not assigned
	 * to a square, or create a new piece if none found.
	 * 
	 * @param string $type either a full class name or an alias
	 */
	public function getUnusedPiece($type) {
		if (array_key_exists($type, self::$pieceAlias)) {
			$type = self::$pieceAlias[$type];
		}
		
		if (!in_array($type, self::$pieceAlias)) {
			throw new PieceTypeException("Invalid piece type $type");
		}
		
		foreach ($this->pieces as $piece) {
			if (is_a($piece, $type) && is_null($piece->location)) {
				return $piece;
			}
		}
		
		$result = new $type();
		$this->pieces[] = $result;
		return $result;
	}
	
	public static function squareIndex($file, $rank) {
		return 16 * $rank + $file;
	}
	
	public static function validSquare($square) {
		return $square >= 0 && ($square & 0x88) == 0;
	}
}

/**
 * A class representing a move in a position.
 * 
 */
class Move {
	
	public $from;
	public $to;
	
	public $result;
	
}

/*
 * Exceptions
 */
class FENException extends Exception {
	
}

class Piece {
	
	public $colour;
	public $location;
	public $board;
	public $rules = array();
	public $hasMoved = false;
	
	const WHITE = 0;
	const BLACK = 1;
	
	public function __construct() {
		
	}
	
	public function algebraicSymbol() {
		$symbols = array_flip(Context::$pieceAlias);
		if (!array_key_exists(get_class($this), $symbols)) {
			throw new PieceTypeException("Invalid piece type " . get_class($this));
		} else {
			return $symbols[get_class($this)];
		}
	}
	
}

class Knight extends Piece {
	
	public function __construct() {
		parent::__construct();
		$this->rules[] = new Rule(new PrerequisiteNull(), new MoveTypeJump(array(new DirectionKnight())), new ResultSimple());
	}
	
}

class Pawn extends Piece {
	
	public function __construct() {
		parent::__construct();
		$this->rules[] = new Rule(new PrerequisitePawnOnHomeRank(), new MoveTypeSlide(array(new DirectionLinearForward($this)), MoveType::NOCAPTURES, 2), new ResultSimple());
		$this->rules[] = new Rule(new PrerequisitePawnOnThirdToSixthRank(), new MoveTypeJump(array(new DirectionLinearForward($this)), MoveType::NOCAPTURES), new ResultSimple());
		$this->rules[] = new Rule(new PrerequisitePawnOnHomeRank(), new MoveTypeJump(array(new DirectionDiagonalForward($this)), MoveType::CAPTURESONLY), new ResultSimple());
		$this->rules[] = new Rule(new PrerequisitePawnOnThirdToSixthRank(), new MoveTypeJump(array(new DirectionDiagonalForward($this)), MoveType::CAPTURESONLY), new ResultSimple());
		$this->rules[] = new Rule(new PrerequisitePawnOnQueeningRank(), new MoveTypeJump(array(new DirectionLinearForward($this)), MoveType::NOCAPTURES), new ResultQueening());
		$this->rules[] = new Rule(new PrerequisitePawnOnQueeningRank(), new MoveTypeJump(array(new DirectionDiagonalForward($this)), MoveType::CAPTURESONLY), new ResultQueening());
		// TODO: Add e.p.
	}
	
}

class Bishop extends Piece {
	
	public function __construct() {
		parent::__construct();
		$this->rules[] = new Rule(new PrerequisiteNull(), new MoveTypeSlide(array(new DirectionDiagonal())), new ResultSimple());
	}
	
}

class Rook extends Piece {
	
	public function __construct() {
		parent::__construct();
		$this->rules[] = new Rule(new PrerequisiteNull(), new MoveTypeSlide(array(new DirectionLinear())), new ResultSimple());
	}
	
}

class Queen extends Piece {
	
	public function __construct() {
		parent::__construct();
		$this->rules[] = new Rule(new PrerequisiteNull(), new MoveTypeSlide(array(new DirectionDiagonal(), new DirectionLinear())), new ResultSimple());
	}
	
}

class King extends Piece {
	public function __construct() {
		parent::__construct();
		$this->rules[] = new Rule(new PrerequisiteNull(), new MoveTypeJump(array(new DirectionDiagonal(), new DirectionLinear())), new ResultSimple());
		// TODO: Add castling rules
	}
}

class WhitePawn extends Pawn {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::WHITE;
	}
	
}

class WhiteKnight extends Knight {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::WHITE;
	}
	
}

class WhiteBishop extends Bishop {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::WHITE;
	}
	
}

class WhiteRook extends Rook {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::WHITE;
	}
	
}

class WhiteQueen extends Queen {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::WHITE;
	}
	
}

class WhiteKing extends King {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::WHITE;
	}
	
}

class BlackPawn extends Pawn {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::BLACK;
	}
	
}

class BlackKnight extends Knight {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::BLACK;
	}
	
}

class BlackBishop extends Bishop {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::BLACK;
	}
	
}

class BlackRook extends Rook {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::BLACK;
	}
	
}

class BlackQueen extends Queen {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::BLACK;
	}
	
}

class BlackKing extends King {
	
	public function __construct() {
		parent::__construct();
		$this->colour = self::BLACK;
	}
	
}
/*
 * Rule
 */
class Rule {
	
	public function __construct(Prerequisite $prerequisite, MoveType $moveType, Result $result) {
		$this->prerequisite = $prerequisite;
		$this->moveType = $moveType;
		$this->result = $result;
	}
	
}

/*
 * Move types
 */
class MoveType {
	
	const MOVEORCAPTURE = 0;
	const NOCAPTURES = 1;
	const CAPTURESONLY = 2;
	
	public $directions = array();
	
	public function generateMoves(Context $context, Piece $piece) {
		throw new Exception("generateMoves() not implemented for " . get_class($this));
	}
	
}

class MoveTypeSlide extends MoveType {
	
	public function __construct(array $directions, $moveType=MoveType::MOVEORCAPTURE, $max=7) {
		$this->moveType = $moveType;
		$this->directions = $directions;
		$this->max = $max;
	}
	
	public function generateMoves(Context $context, Piece $piece) {
		$result = array();
		foreach ($this->directions as $direction) {
			foreach ($direction->offsets as $offset) {
				$target = $piece->location;
				for ($i = 0; $i < $this->max; $i++) {
					$target += $offset;
					if (!Context::validSquare($target)) {
						continue 2;
					}
					$targetPiece = $context->board[$target];
					if (is_null($targetPiece)) {
						if ($this->moveType == MoveType::CAPTURESONLY) {
							continue 2;
						}
						$move = new Move();
						$move->from = $piece->location;
						$move->to = $target;
						$result[] = $move;
					} else if ($targetPiece->colour == $piece->colour || $this->moveType == MoveType::NOCAPTURES) {
						continue 2;
					} else if ($this->moveType == MoveType::MOVEORCAPTURE) {
						$move = new Move();
						$move->from = $piece->location;
						$move->to = $target;
						$result[] = $move;
					}
				}
			}
		}
		return $result;
	}
	
}

class MoveTypeJump extends MoveTypeSlide {
	
	public function __construct(array $directions, $moveType=MoveType::MOVEORCAPTURE) {
		parent::__construct($directions, $moveType);
		$this->max = 1;
	}
	
}

/*
 * Directions
 */
class Direction {
	
	public $offsets = array();
	
}

class DirectionKnight extends Direction {

	public $offsets = array(-33, -31, -18, -14, 14, 18, 31, 33);
	
}

class DirectionDiagonal extends Direction {
	
	public $offsets = array(-17, -15, 15, 17);
}

class DirectionLinear extends Direction {

	public $offsets = array(-16, -1, 1, 16);
	
}

class DirectionLinearForward extends Direction {

	public function __construct($piece) {
		if ($piece->colour == Piece::WHITE) {
			$this->offsets = array(1, 16);
		} else if ($piece->colour == Piece::BLACK) {
			$this->offsets = array(-16, -1);
		} else {
			throw new Exception("Invalid piece colour");
		}
	}
	
}

class DirectionDiagonalForward extends Direction {

	public function __construct($piece) {
		if ($piece->colour == Piece::WHITE) {
			$this->offsets = array(15, 17);
		} else if ($piece->colour == Piece::BLACK) {
			$this->offsets = array(-15, -17);
		} else {
			throw new Exception("Invalid piece colour");
		}
	}

}

/*
 * Prerequisites
 */
class Prerequisite {
	
}

class PrerequisiteNull extends Prerequisite {
	
	public function satisfied($context, $piece) {
		return true;
	}
	
}

class PrerequisitePawnOnHomeRank extends Prerequisite {
	
	public function satisfied($context, $piece) {
		if ($piece->colour == Piece::WHITE) {
			return ($piece->location >> 4 == 1);
		}
		if ($piece->colour == Piece::BLACK) {
			return ($piece->location >> 4 == 6);
		}
		return false;
	}
	
}

class PrerequisitePawnOnQueeningRank extends Prerequisite {
	
	public function satisfied($context, $piece) {
		if ($piece->colour == Piece::WHITE) {
			return ($piece->location >> 4 == 6);
		}
		if ($piece->colour == Piece::BLACK) {
			return ($piece->location >> 4 == 1);
		}
		return false;
	}
	
}

class PrerequisitePawnOnThirdToSixthRank extends Prerequisite {
	
	public function satisfied($context, $piece) {
		return ($piece->location >> 4 > 1 && $piece->location >> 4 < 6);
	}
	
}

/*
 * Results. These are used to translate a Move object into a delta object between one position
 * and another where required (or, in the case of queening, a number of deltas). These deltas are used for altering the context when making a move
 * and for undoing the move again.
 */
class Result {
	
}

class ResultSimple extends Result {
	
}

class ResultQueening extends Result {
	
}