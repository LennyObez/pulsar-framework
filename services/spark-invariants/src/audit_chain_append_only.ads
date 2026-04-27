--  Audit chain append-only enforcement — SPARK 2014 spec.
--
--  Per Decision 2.54 (v2.3 lock-in): proves that for any (Prev_Root,
--  Entry, New_Root) triple, New_Root is uniquely determined by
--  Prev_Root + Entry under the RFC 6962 Merkle tree construction +
--  Ed25519-signed leaf encoding. Equivalently: no other New_Root
--  value satisfies Verify_Append (Prev_Root, Entry, New_Root) = True.
--
--  Linked into pulsar-kernel + pulsar-audit via the C ABI declared at
--  the bottom of this spec.

with System;
with System.Storage_Elements; use System.Storage_Elements;

package Audit_Chain_Append_Only
  with SPARK_Mode => On,
       Pure
is

   --  RFC 6962 Merkle root: 32-byte opaque hash.
   type Merkle_Root is array (1 .. 32) of Storage_Element;

   --  Audit log entry: opaque byte sequence (the canonicalised + Ed25519-
   --  signed payload). Bounded to 64 KiB (65 536 bytes) for GNATprove
   --  tractability per Decision 2.54 — long entries are rejected at the
   --  pulsar-audit boundary before they reach this verifier.
   subtype Bounded_Entry_Length is Natural range 0 .. 65_536;
   type Audit_Entry is array (Bounded_Entry_Length range <>) of Storage_Element;

   --  Verify that New_Root is the unique RFC 6962 Merkle root extending
   --  Prev_Root with Entry.
   function Verify_Append
     (Prev_Root : Merkle_Root;
      Entry_Bytes : Audit_Entry;
      New_Root  : Merkle_Root) return Boolean
   with
     Post => (if Verify_Append'Result then
                --  Append-only invariant: any New_Root' satisfying the
                --  postcondition with the same (Prev_Root, Entry_Bytes)
                --  must equal New_Root.
                (for all Alt_Root in Merkle_Root =>
                   (if Verify_Append (Prev_Root, Entry_Bytes, Alt_Root)
                       then Alt_Root = New_Root)));

   --  C ABI for FFI to pulsar-kernel + pulsar-audit ==========================

   --  Returns 1 iff verified, 0 otherwise. Length-checked at the boundary.
   function C_Verify_Append
     (Prev_Root_Bytes : System.Address;
      Entry_Bytes_Ptr : System.Address;
      Entry_Bytes_Len : Natural;
      New_Root_Bytes  : System.Address) return Integer
   with
     Export,
     Convention => C,
     External_Name => "pulsar_spark_verify_audit_append";

end Audit_Chain_Append_Only;
