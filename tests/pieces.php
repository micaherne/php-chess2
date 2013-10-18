<?php

require_once __DIR__.'/../pieces.php';

class PiecesTest extends PHPUnit_Framework_TestCase {
	
	public function setUp() {
		
	}
	
	public function testKnight() {
		$k = new WhiteKnight();
		$this->assertEquals(Piece::WHITE, $k->colour);
		$this->assertFalse($k->hasMoved);
	}
	
	public function testContext() {
		$c = new Context();
		$this->assertCount(128, $c->board);
		$this->assertCount(0, $c->pieces);
		
		$c->startPosition();
		$this->assertCount(32, $c->pieces);
		$this->assertInstanceOf('WhiteRook', $c->board[0]);
		$this->assertInstanceOf('WhiteKnight', $c->board[1]);
		$this->assertInstanceOf('WhiteBishop', $c->board[2]);
		$this->assertInstanceOf('WhiteQueen', $c->board[3]);
		$this->assertInstanceOf('WhiteKing', $c->board[4]);
	}
	
	public function testGetUnusedPiece() {
		$c = new Context();
		$p = $c->getUnusedPiece('N');
		$this->assertTrue(is_a($p, 'WhiteKnight'));
	}
	
	public function testAlgebraicSymbol() {
		$c = new Context();
		$p = $c->getUnusedPiece('N');
		$this->assertEquals('N', $p->algebraicSymbol());
	}
	
	public function testStartPosition() {
		$c = new Context();
		$c->startPosition();
		$this->assertTrue($c->whiteToMove);
	}
	
	public function testDisplay() {
		$c = new Context();
		$c->fromFEN('r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1');
	}
	
	public function testGeneratePseudoLegalMoves() {
		$c = new Context();
		$c->fromFEN('r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1');
		$piece = $c->board[0x25];
		$this->assertInstanceOf('WhiteKnight', $piece);
		$moves = $c->generatePseudoLegalMoves($piece);
		$this->assertCount(5, $moves);

		$piece = $c->board[0x31];
		$this->assertInstanceOf('WhiteBishop', $piece);
		$moves = $c->generatePseudoLegalMoves($piece);

		$this->assertCount(7, $moves);
	}
	
	public function testGenerateAllPseudoLegalMoves() {
		$c = new Context();
		$c->startPosition();
		$moves = $c->generateAllPseudoLegalMoves();
		$this->assertCount(20, $moves);
	}
	
	public function testAttacksSquare() {
		$c = new Context();
		$c->fromFEN('r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1');
		$piece = $c->board[0x51];
		$this->assertInstanceOf('BlackBishop', $piece);
		$this->assertTrue($c->attacks($piece, 0x24));
		
		$c->startPosition();
		$piece = $c->board[0x15];
		$this->assertInstanceOf('WhitePawn', $piece);
		//$this->assertTrue($c->attacks($piece, 0x26));
		//$this->assertFalse($c->attacks($piece, 0x67));
	}
}