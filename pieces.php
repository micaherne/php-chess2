<?php

class Context {
	
	public $board;
	public $pieces;
	
	public $undo;
	
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
	
	public $epSquare;
	
	public function __construct() {
		$this->_init();
	}
	
	private function _init() {
		$this->board = array();
		for ($i = 0; $i < 128; $i++) {
			$this->board[] = null;
		}
		$this->pieces = array();
		$this->undo = array();
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
		$this->_init();
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
				if (is_null($move->result)) {
					$move->result = $rule->result;
				}
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
	 * Make a move and push undo data onto the undo stack. This method is also the main
	 * way of checking pseudo-legal moves do not leave the moving side in check.
	 * 
	 * Note that we do not check the legality of moves in any other way, and assume that
	 * the move passed to this method is at least pseudo-legal (i.e. would be a legal move
	 * if it doesn't leave or put the moving side in check). Checking this would be a 
	 * large performance hit, so we have to assume that calling code is providing a correct
	 * move.
	 * 
	 * @param Move $move
	 * @return boolean whether the move was made successfully
	 */
	public function makeMove(Move $move) {
		if (is_a($move->result, 'ResultSimple')) {
			$undo = array('board' => array(
				$move->from => $this->board[$move->from],
				$move->to => $this->board[$move->to]
			));
			$takenPiece = $this->board[$move->to];
			$movedPiece = $this->board[$move->from];
			if (!is_null($takenPiece)) {
				$takenPiece->location = null;
			}
			$this->board[$move->to] = $movedPiece;
			$movedPiece->location = $move->to;
			$this->board[$move->from] = null;
			$this->whiteToMove = !$this->whiteToMove;
			
			array_push($this->undo, $undo);
			
			if ($this->isCheck($movedPiece->colour)) {
				$this->undoMove();
				return false;
			}
		}
		
		return true;
	}
	
	public function undoMove() {
		$undo = array_pop($this->undo);
		if (is_null($undo)) {
			throw new Exception("Attempting to get non-existent undo data");
		}
		$this->whiteToMove = !$this->whiteToMove;
		foreach ($undo['board'] as $square => $piece) {
			$this->board[$square] = $piece;
			if (!is_null($piece)) {
				$piece->location = $square;
			}
		}
		// TODO: Implement
	}
	
	public function isCheck($colour, King $king=null) {
		if (is_null($king)) {
			foreach ($this->pieces as $piece) {
				if (is_null($piece->location)) {
					continue;
				}
				if ($piece->colour == $colour && is_a($piece, 'King')) {
					$king = $piece;
					break;
				}
			}
			if (is_null($king)) {
				throw new Exception("King not found");
			}
		}
		
		foreach ($this->pieces as $piece) {
			if (is_null($piece->location)) {
				continue;
			}
			if ($piece->colour == $colour) {
				continue;
			}
			if ($this->attacks($piece, $king->location)) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Determine whether the given piece attacks a square. This will return false
	 * where square contains a piece of the same colour.
	 * 
	 * @param unknown $piece
	 * @param unknown $square
	 */
	public function attacks($piece, $square) {
		if (is_null($piece)) {
			return false;
		}
		foreach ($piece->rules as $rule) {
			if (!$rule->prerequisite->satisfied($this, $piece)) {
				continue;
			}
			if ($rule->attacks($this, $piece, $square)) {
				return true;
			}
		}
		
		return false;
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
	public $queeningClass;
	public $result;
	
	public function __construct($from, $to, $queeningClass=null, $result=null) {
		$this->from = $from;
		$this->to = $to;
		$this->queeningClass = $queeningClass;
		$this->result = $result;
	}
	
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
		$this->rules[] = new Rule(new PrerequisiteNull(), new MoveTypePawn($this), new ResultSimple());
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
	
	public $prerequisite;
	public $moveType;
	public $result;
	
	public function __construct(Prerequisite $prerequisite, MoveType $moveType, Result $result) {
		$this->prerequisite = $prerequisite;
		$this->moveType = $moveType;
		$this->result = $result;
	}
	
	public function generateMoves(Context $context, Piece $piece) {
		return $this->moveType->generateMoves($context, $piece);
	}
	
	public function attacks(Context $context, Piece $piece, $square) {
		// TODO: This is a very naive implementation which won't perform well
		// TODO: Also, generateMoves doesn't give us the correct pawn moves for attacks
		$moves = $this->moveType->generateAttacks($context, $piece);
		foreach ($moves as $move) {
			if ($move->to == $square) {
				return true;
			}
		}
		return false;
	}
	
}

/*
 * Move types
 */
class MoveType {
	
	const NOCAPTURES = 1;
	const CAPTURESONLY = 2;
	const MOVEORCAPTURE = 3;
	
	public $moveType;
	public $directions = array();
	
	public function generateMoves(Context $context, Piece $piece) {
		throw new Exception("generateMoves() not implemented for " . get_class($this));
	}
	
	/**
	 * Generate a list of the squares / pieces attacked by a piece. For pieces this is
	 * the same as generateMoves, but for pawns it is different due to the way they
	 * capture.
	 * 
	 * @param Context $context
	 * @param Piece $piece
	 */
	public function generateAttacks(Context $context, Piece $piece) {
		if ($this->moveType == self::NOCAPTURES) {
			continue;
		}
		return $this->generateMoves($context, $piece);
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
						break;
					}
					$targetPiece = $context->board[$target];
					if (is_null($targetPiece)) {
						if ($this->moveType == MoveType::CAPTURESONLY) {
							continue 2;
						}
						$result[] = new Move($piece->location, $target);
					} else if ($targetPiece->colour == $piece->colour || $this->moveType == MoveType::NOCAPTURES) {
						break;
					} else if ($this->moveType == MoveType::MOVEORCAPTURE) {
						$result[] = new Move($piece->location, $target);
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

/**
 * Pawn moves are so unlike piece moves that we give them their own move type. In theory
 * we could do pawns with multiple rules but it is simpler and better-performing to do a 
 * custom type.
 *
 */
class MoveTypePawn extends MoveType {
	
	private $piece;
	private $directionMove;
	private $directionCapture;
	
	private static $queeningPieces = array(
		Piece::WHITE => array(
			'WhiteKnight',
			'WhiteBishop',
			'WhiteRook',
			'WhiteQueen',
			'WhiteKing'),
			
		Piece::BLACK => array(
			'BlackKnight',
			'BlackBishop',
			'BlackRook',
			'BlackQueen',
			'BlackKing')
	);
	
	public function __construct(Piece $piece) {
		$this->piece = $piece;
		$this->directionCapture = new DirectionDiagonalForward($piece);
		$this->directionMove = new DirectionLinearForward($piece);
	}
	
	public function generateMoves(Context $context, Piece $piece) {
		$result = array();
		
		$homeRank = null;
		$queeningRank = null;
		if ($piece->colour == Piece::WHITE) {
			$homeRank = 1;
			$queeningRank = 7;
		} else if ($piece->colour == Piece::BLACK) {
			$homeRank = 6;
			$queeningRank = 0;
		}
		
		// Move forward one or two
		$offset = $this->directionMove->offsets[0]; // there is only one
		$target = $piece->location + $offset;
		
		if (is_null($context->board[$target]) && Context::validSquare($target)) {
			if ($piece->location >> 4 == $queeningRank) {
				foreach (self::$queeningPieces[$piece->colour] as $className) {
					$result[] = new Move($piece->location, $target, $className);
				}
			} else {
				$result[] = new Move($piece->location, $target);
			}
			if ($piece->location >> 4 == $homeRank) {
				$target += $offset;
				if (is_null($context->board[$target]) && Context::validSquare($target)) {
					$result[] = new Move($piece->location, $target);
				}
			}
		}
		
		// Captures
		foreach ($this->directionCapture->offsets as $offset) {
			$target = $piece->location;

			$target += $offset;
			if (Context::validSquare($target)) {
				$targetPiece = $context->board[$target];
				if ($context->epSquare == $target) {
					$result[] = new Move($piece->location, $target, null, new ResultEPCapture());
				} else if (!is_null($targetPiece) && ($targetPiece->colour != $piece->colour)) {
					$result[] = new Move($piece->location, $target);
				}
			}
		}
		
		return $result;
	}

	public function generateAttacks(Context $context, Piece $piece) {
		$result = array();
		foreach ($this->directionCapture->offsets as $offset) {
			$target = $piece->location;
		
			$target += $offset;
			if (Context::validSquare($target)) {
				$result[] = new Move($piece->location, $target);
			}
		}
		
		return $result;
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
			$this->offsets = array(16);
		} else if ($piece->colour == Piece::BLACK) {
			$this->offsets = array(-16);
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

class ResultEPCapture extends Result {
	
}